<?php

namespace App\Services;

use App\Exceptions\FireflyException;
use App\Exceptions\GeneralException;
use App\Http\Controllers\Base\BaseController;
use App\Http\Controllers\Base\BaseControllerFirefly;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * AI category suggestions, stored by the category-suggester as inactive Firefly rules.
 * The contract between the two is docs/rule-suggestions.md.
 */
class RuleSuggestionService
{
    const MARKER = 'pico-suggestion:v1 ';
    const TRIGGER_TYPES = ['description_contains', 'destination_account_contains', 'destination_account_is', 'destination_account_id'];
    // Catch-all category rules, in this order, that stay at the end of the Categorization group
    const GENERIC_TAIL_CATEGORIES = ['Bills', 'Shopping', 'Other'];
    const MAX_MATCHED_TRANSACTIONS = 500;
    const PAGE_SIZE = 50;

    const TRANSACTION_FIELDS = ['description', 'amount', 'date', 'type', 'destination_id', 'destination_name', 'category_name', 'currency_code', 'currency_symbol', 'currency_decimal_places'];

    public function getAll()
    {
        $group = $this->getRuleGroup(config('app.rule_group_suggestions'));
        $rules = $group ? $this->getRules($group) : [];
        $suggestions = array_values(array_filter(array_map(fn($rule) => $this->toSuggestion($rule), $rules)));
        return $this->attachTransactions($suggestions);
    }

    public function getOne($id)
    {
        return $this->attachTransactions([$this->findSuggestion($id)])[0];
    }

    /**
     * Counts the transactions the triggers match, over all time.
     */
    public function preview($triggers)
    {
        $journals = $this->findMatchingJournals($this->uniqueTriggers($triggers));
        $uncategorizedCount = count(array_filter($journals, fn($journal) => !$journal['category_name']));
        return [
            'uncategorized_count' => $uncategorizedCount,
            'categorized_count' => count($journals) - $uncategorizedCount,
        ];
    }

    /**
     * Adds the triggers to the "Category: {category}" rule and categorizes the suggestion's transactions plus every
     * uncategorized transaction the triggers match. Already categorized transactions are never changed.
     * This replaces running the Categorization group: Firefly's API trigger fires each rule on its own, ignoring the
     * "Stop: already categorized" guard, so it would overwrite existing categories.
     */
    public function approve($id, $category, $triggers)
    {
        $suggestion = $this->findSuggestion($id);
        $triggers = $this->uniqueTriggers($triggers);

        // Search first: a too broad trigger fails here, before anything is written
        $matchedJournals = array_filter($this->findMatchingJournals($triggers), fn($journal) => !$journal['category_name']);
        $transactions = [...fget($suggestion, 'attributes.transactions'), ...array_values($matchedJournals)];

        $result = $this->saveCategoryRule($category, $triggers);
        $result['transactions_count'] = $this->setCategory($transactions, $category);
        $this->firefly('delete', "rules/$id", [], BaseController::HTTP_CODE_OK_DELETE);
        return $result;
    }

    /**
     * Sets the category on the suggestion's transactions only, without writing a rule.
     */
    public function categorize($id, $category)
    {
        $suggestion = $this->findSuggestion($id);
        $transactionsCount = $this->setCategory(fget($suggestion, 'attributes.transactions'), $category);
        $this->firefly('delete', "rules/$id", [], BaseController::HTTP_CODE_OK_DELETE);
        return ['transactions_count' => $transactionsCount];
    }

    public function reject($id)
    {
        $this->findSuggestion($id);
        $this->firefly('delete', "rules/$id", [], BaseController::HTTP_CODE_OK_DELETE);
    }

    // ---------------------------

    private function findSuggestion($id)
    {
        $rule = fget($this->firefly('get', "rules/$id"), 'data');
        $isSuggestion = fget($rule, 'attributes.rule_group_title') === config('app.rule_group_suggestions');
        $suggestion = $isSuggestion ? $this->toSuggestion($rule) : null;
        if (!$suggestion) {
            throw new GeneralException('Rule suggestion not found', BaseController::HTTP_CODE_NOT_FOUND);
        }
        return $suggestion;
    }

    /**
     * Returns null for rules without the marker or with an unknown contract version: Pico hides those.
     */
    private function toSuggestion($rule)
    {
        // Firefly returns rule descriptions HTML-escaped
        $description = html_entity_decode(fget($rule, 'attributes.description') ?? '', ENT_QUOTES);
        if (!str_starts_with($description, self::MARKER)) {
            return null;
        }
        $metadata = json_decode(substr($description, strlen(self::MARKER)), true);
        if ((int)fget($metadata, 'v') !== 1) {
            return null;
        }

        return [
            'id' => fget($rule, 'id'),
            'type' => 'rule-suggestions',
            'attributes' => [
                'title' => fget($rule, 'attributes.title'),
                'triggers' => array_map(fn($trigger) => Arr::only($trigger, ['type', 'value']), fget($rule, 'attributes.triggers') ?? []),
                ...$metadata,
            ],
        ];
    }

    private function attachTransactions($suggestions)
    {
        $transactionIds = array_unique(array_merge(...array_map(fn($suggestion) => array_column(fget($suggestion, 'attributes.transactions') ?? [], 'transaction_id'), $suggestions)));
        $groups = $this->getTransactionGroups($transactionIds);

        foreach ($suggestions as &$suggestion) {
            $suggestion['attributes']['transactions'] = array_map(function ($transaction) use ($groups) {
                $splits = fget($groups, $transaction['transaction_id'] . '.attributes.transactions') ?? [];
                $split = fcollect($splits)->firstWhere('transaction_journal_id', $transaction['journal_id']) ?? [];
                return $transaction + Arr::only($split, self::TRANSACTION_FIELDS);
            }, fget($suggestion, 'attributes.transactions') ?? []);
        }
        return $suggestions;
    }

    /**
     * Fetches the transaction groups in parallel, keyed by id. Missing (deleted) transactions are left out.
     */
    private function getTransactionGroups($ids)
    {
        if (!$ids) {
            return [];
        }
        $headers = (new BaseControllerFirefly())->getHeaders();
        $responses = Http::pool(fn(Pool $pool) => array_map(fn($id) => $pool->as((string)$id)->withHeaders($headers)->timeout(10)->get($this->getUrl("transactions/$id")), $ids));

        $responses = array_filter($responses, fn($response) => $response instanceof Response && $response->ok());
        return array_map(fn($response) => $response->json('data'), $responses);
    }

    /**
     * Searches each trigger over all time. Returns the matched journals keyed by journal id.
     */
    private function findMatchingJournals($triggers)
    {
        $journals = [];
        foreach ($triggers as $trigger) {
            $query = $trigger['type'] . ':"' . addcslashes($trigger['value'], '"\\') . '"';
            $page = 1;
            do {
                $body = $this->firefly('get', 'search/transactions', ['query' => $query, 'limit' => self::PAGE_SIZE, 'page' => $page]);
                $data = fget($body, 'data') ?? [];
                foreach ($data as $group) {
                    foreach (fget($group, 'attributes.transactions') ?? [] as $split) {
                        $journals[$split['transaction_journal_id']] = [
                            'transaction_id' => fget($group, 'id'),
                            'journal_id' => $split['transaction_journal_id'],
                            'category_name' => fget($split, 'category_name'),
                        ];
                    }
                }
                if (count($journals) > self::MAX_MATCHED_TRANSACTIONS) {
                    throw new GeneralException('The conditions are too broad. More than ' . self::MAX_MATCHED_TRANSACTIONS . ' transactions match.', BaseController::HTTP_CODE_UNPROCESSABLE_ENTITY);
                }
                $page++;
            } while ($page <= (fget($body, 'meta.pagination.total_pages') ?? 1) && count($data) > 0);
        }
        return $journals;
    }

    /**
     * Merges the triggers into the "Category: {category}" rule, creating it above the generic tail rules when missing.
     */
    private function saveCategoryRule($category, $triggers)
    {
        $group = $this->getRuleGroup(config('app.rule_group_categorization'));
        if (!$group) {
            throw new GeneralException('Rule group "' . config('app.rule_group_categorization') . '" not found');
        }
        $rules = fcollect($this->getRules($group));
        $title = "Category: $category";
        $rule = $rules->firstWhere('attributes.title', $title);

        if ($rule) {
            $existingTriggers = array_map(fn($trigger) => Arr::only($trigger, ['type', 'value', 'active', 'prohibited', 'stop_processing']), fget($rule, 'attributes.triggers') ?? []);
            $newTriggers = array_values(array_diff_key($this->keyTriggers($triggers), $this->keyTriggers($existingTriggers)));
            if ($newTriggers) {
                $this->firefly('put', 'rules/' . fget($rule, 'id'), ['triggers' => [...$existingTriggers, ...$this->toRuleTriggers($newTriggers)]]);
            }
            return ['rule_id' => fget($rule, 'id'), 'is_rule_created' => false, 'triggers_added_count' => count($newTriggers)];
        }

        $tailIndex = array_search($category, self::GENERIC_TAIL_CATEGORIES);
        $tailCategories = $tailIndex === false ? self::GENERIC_TAIL_CATEGORIES : array_slice(self::GENERIC_TAIL_CATEGORIES, $tailIndex + 1);
        $tailOrder = $rules->whereIn('attributes.title', array_map(fn($tailCategory) => "Category: $tailCategory", $tailCategories))->min('attributes.order');

        $body = [
            'title' => $title,
            'rule_group_id' => fget($group, 'id'),
            'trigger' => 'store-journal',
            'active' => true,
            'strict' => false,
            'stop_processing' => true,
            'triggers' => $this->toRuleTriggers($triggers),
            'actions' => [['type' => 'set_category', 'value' => $category, 'active' => true, 'stop_processing' => false]],
        ];
        if ($tailOrder) {
            $body['order'] = $tailOrder;
        }
        $rule = fget($this->firefly('post', 'rules', $body), 'data');
        return ['rule_id' => fget($rule, 'id'), 'is_rule_created' => true, 'triggers_added_count' => count($triggers)];
    }

    /**
     * Sets the category on the given journals. Returns the number of journals changed.
     */
    private function setCategory($transactions, $category)
    {
        $journalIdsByGroup = fcollect($transactions)->groupBy('transaction_id')->map(fn($list) => $list->pluck('journal_id')->map(fn($id) => (string)$id)->all());

        foreach ($journalIdsByGroup as $transactionId => $journalIds) {
            // Firefly deletes the splits missing from an update => send every split of the group
            $splits = fget($this->firefly('get', "transactions/$transactionId"), 'data.attributes.transactions') ?? [];
            $splits = array_map(function ($split) use ($journalIds, $category) {
                $journalId = (string)$split['transaction_journal_id'];
                return ['transaction_journal_id' => $journalId] + (in_array($journalId, $journalIds) ? ['category_name' => $category] : []);
            }, $splits);
            $this->firefly('put', "transactions/$transactionId", ['transactions' => $splits]);
        }
        return $journalIdsByGroup->flatten()->unique()->count();
    }

    private function uniqueTriggers($triggers)
    {
        $triggers = array_map(fn($trigger) => ['type' => $trigger['type'], 'value' => trim($trigger['value'])], $triggers);
        return array_values($this->keyTriggers($triggers));
    }

    // Keyed by type and value, keeping the first of duplicates. Firefly matches the values case-insensitively.
    private function keyTriggers($triggers)
    {
        $key = fn($trigger) => $trigger['type'] . ':' . mb_strtolower(trim($trigger['value']));
        return fcollect($triggers)->unique($key)->keyBy($key)->all();
    }

    private function toRuleTriggers($triggers)
    {
        return array_map(fn($trigger) => [...$trigger, 'active' => true, 'prohibited' => false, 'stop_processing' => true], $triggers);
    }

    private function getRuleGroup($title)
    {
        return fcollect($this->getAllPages('rule-groups'))->firstWhere('attributes.title', $title);
    }

    private function getRules($group)
    {
        return fcollect($this->getAllPages('rule-groups/' . fget($group, 'id') . '/rules'))->sortBy('attributes.order')->values()->all();
    }

    private function getAllPages($path)
    {
        $list = [];
        $page = 1;
        do {
            $body = $this->firefly('get', $path, ['limit' => self::PAGE_SIZE, 'page' => $page]);
            $list = [...$list, ...(fget($body, 'data') ?? [])];
            $page++;
        } while ($page <= (fget($body, 'meta.pagination.total_pages') ?? 1));
        return $list;
    }

    private function firefly($method, $path, $data = [], $expectedStatus = BaseController::HTTP_CODE_OK)
    {
        $response = (new BaseControllerFirefly())->getHttpClient()->$method($this->getUrl($path), $data);
        if ($response->status() !== $expectedStatus) {
            throw new FireflyException($response);
        }
        return $response->json();
    }

    private function getUrl($path)
    {
        return config('app.firefly_url') . "/api/v1/$path";
    }
}
