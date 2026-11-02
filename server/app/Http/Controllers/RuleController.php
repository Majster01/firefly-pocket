<?php

namespace App\Http\Controllers;

use App\Exceptions\FireflyException;
use App\Exceptions\GeneralException;
use App\Http\Controllers\Base\BaseController;
use App\Http\Controllers\Base\BaseControllerFirefly;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as FireflyResponse;
use Illuminate\Http\Request;

/**
 * Firefly III's own rules and rule groups, forwarded as they are, except for the suggester's group
 * (RULE_GROUP_SUGGESTIONS). That group belongs to the Suggestions pages (RuleSuggestionService): the Rules pages
 * never list it, open it, change it, or move a rule into it.
 */
class RuleController extends BaseControllerFirefly
{
    // Testing or running a rule makes Firefly search every transaction in the dates (up to 31,337) and write the
    // answer out in full, which takes far longer than a read. nginx gives a request 60 seconds (docker/conf/nginx).
    const SLOW_SECONDS = 55;

    // The conditions of a rule that is not saved (yet) are tested with Firefly's transaction search
    const TEST_PAGE_SIZE = 50;
    const TEST_MAX_TRANSACTIONS = 500;

    public function __construct()
    {
        parent::__construct('/api/v1/');
    }

    // ------------------------------------------------------------------ rule groups

    public function getGroups(Request $request)
    {
        return $this->listWithoutHidden($request, 'attributes.title');
    }

    public function getGroup(Request $request)
    {
        $response = $this->forward($request);
        if ($response->ok() && $this->isHidden(fget($response->json(), 'data.attributes.title'))) {
            $this->notFound();
        }
        return $this->pass($response);
    }

    /** The rules of one group. Each rule names its group, so the hidden one is told apart without another call. */
    public function getGroupRules(Request $request)
    {
        $response = $this->forward($request);
        if ($response->ok() && fcollect(fget($response->json(), 'data') ?? [])->contains(fn($rule) => $this->isHidden(fget($rule, 'attributes.rule_group_title')))) {
            $this->notFound();
        }
        return $this->pass($response);
    }

    public function createGroup(Request $request)
    {
        $this->refuseHiddenTitle($request->input('title'));
        return $this->pass($this->forward($request));
    }

    /** PUT and DELETE rule-groups/{id}, GET rule-groups/{id}/test, POST rule-groups/{id}/trigger */
    public function changeGroup(Request $request, $id)
    {
        if ($missing = $this->guardGroup($id)) {
            return $missing;
        }
        if ($request->isMethod('PUT')) {
            $this->refuseHiddenTitle($request->input('title'));
        }
        return $this->pass($this->forward($request));
    }

    // ------------------------------------------------------------------ rules

    public function getRules(Request $request)
    {
        return $this->listWithoutHidden($request, 'attributes.rule_group_title');
    }

    public function getRule(Request $request)
    {
        $response = $this->forward($request);
        if ($response->ok() && $this->isHidden(fget($response->json(), 'data.attributes.rule_group_title'))) {
            $this->notFound();
        }
        return $this->pass($response);
    }

    public function createRule(Request $request)
    {
        $this->refuseHiddenTarget($request);
        return $this->pass($this->forward($request));
    }

    /** PUT and DELETE rules/{id}, GET rules/{id}/test, POST rules/{id}/trigger */
    public function changeRule(Request $request, $id)
    {
        $rule = $this->firefly('get', "rules/$id");
        if (!$rule->ok()) {
            // Firefly answers 401 for an id that does not exist: the web app tells that apart from a bad token
            return $this->pass($rule);
        }
        if ($this->isHidden(fget($rule->json(), 'data.attributes.rule_group_title'))) {
            $this->notFound();
        }
        if ($request->isMethod('PUT')) {
            $this->refuseHiddenTarget($request);
        }
        return $this->pass($this->forward($request));
    }

    /**
     * POST rules/test {triggers: [{type, value, prohibited, active}], strict, start, end}
     *
     * The transactions the conditions as they are on screen would match, saved or not: Firefly's API can only test a
     * rule that is saved, and then only as saved. This asks the transaction search what Firefly's rule engine asks it
     * (SearchRuleEngine): one search with every condition when all must match, one search per condition when any may,
     * both inside the dates. The answer has the shape of a list of transactions; meta.truncated says there were more.
     */
    public function testConditions(Request $request)
    {
        $queries = $this->searchQueries(
            (array)$request->input('triggers', []),
            $request->input('strict') !== false && $request->input('strict') !== 'false',
            $request->input('start'),
            $request->input('end'),
        );
        if (!$queries) {
            throw new GeneralException('Add a condition that is on.', BaseController::HTTP_CODE_UNPROCESSABLE_ENTITY);
        }

        $groups = [];
        $truncated = false;
        try {
            foreach ($queries as $query) {
                $page = 1;
                do {
                    $response = $this->getHttpClient()->timeout(self::SLOW_SECONDS)->get(config('app.firefly_url') . '/api/v1/search/transactions', ['query' => $query, 'limit' => self::TEST_PAGE_SIZE, 'page' => $page]);
                    if (!$response->ok()) {
                        throw new FireflyException($response);
                    }
                    $body = $response->json();
                    foreach (fget($body, 'data') ?? [] as $group) {
                        $groups[fget($group, 'id')] = $group;
                    }
                    if (count($groups) > self::TEST_MAX_TRANSACTIONS) {
                        $truncated = true;
                        break 2;
                    }
                    $page++;
                } while ($page <= (fget($body, 'meta.pagination.total_pages') ?? 1) && count(fget($body, 'data') ?? []) > 0);
            }
        } catch (ConnectionException $e) {
            throw new GeneralException('Firefly took too long to search. Try a shorter date range.', 504, null, $e);
        }

        $date = fn($group) => (string)fget($group, 'attributes.transactions.0.date');
        $groups = array_values($groups);
        usort($groups, fn($a, $b) => strcmp($date($b), $date($a)));
        return response()->json(['data' => array_slice($groups, 0, self::TEST_MAX_TRANSACTIONS), 'meta' => ['truncated' => $truncated]]);
    }

    /** The searches that find what a rule with these conditions matches: the engine's, written as search queries */
    private function searchQueries(array $triggers, bool $strict, $start, $end): array
    {
        $terms = [];
        foreach ($triggers as $trigger) {
            $type = trim((string)fget($trigger, 'type'));
            // Not an operator the search knows: a name of Firefly's own, never typed by the app
            if (!preg_match('/^[a-z_]+$/', $type) || fget($trigger, 'active') === false || $type === 'user_action') {
                continue;
            }
            $value = trim((string)(fget($trigger, 'value') ?? ''));
            // Conditions with nothing to compare (has any category…) are searched as "true", as the rule engine does
            $operand = in_array($value, ['', 'true'], true) ? 'true' : '"' . addcslashes($value, '"\\') . '"';
            $terms[] = (filter_var(fget($trigger, 'prohibited'), FILTER_VALIDATE_BOOLEAN) ? '-' : '') . "$type:$operand";
        }
        if (!$terms) {
            return [];
        }

        $dates = [];
        foreach (['date_after' => $start, 'date_before' => $end] as $operator => $day) {
            if (is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                $dates[] = "$operator:\"$day\"";
            }
        }
        return $strict ? [implode(' ', [...$terms, ...$dates])] : array_map(fn($term) => implode(' ', [$term, ...$dates]), $terms);
    }

    // ------------------------------------------------------------------

    private function isHidden($groupTitle)
    {
        $hidden = (string)config('app.rule_group_suggestions');
        return $hidden !== '' && is_string($groupTitle) && mb_strtolower(trim(html_entity_decode($groupTitle, ENT_QUOTES))) === mb_strtolower($hidden);
    }

    /** A Firefly list with the hidden group (or its rules) left out, its counts lowered to match */
    private function listWithoutHidden(Request $request, $titlePath)
    {
        $response = $this->forward($request);
        if (!$response->ok()) {
            return $this->pass($response);
        }
        $body = $response->json();
        $data = fget($body, 'data') ?? [];
        $kept = array_values(array_filter($data, fn($item) => !$this->isHidden(fget($item, $titlePath))));
        $removed = count($data) - count($kept);
        $body['data'] = $kept;
        foreach (['total', 'count'] as $key) {
            if (is_numeric(fget($body, "meta.pagination.$key"))) {
                fset($body, "meta.pagination.$key", max(0, fget($body, "meta.pagination.$key") - $removed));
            }
        }
        return response()->json($body);
    }

    /** Null when the group may be changed, else the answer to send instead */
    private function guardGroup($id)
    {
        $group = $this->firefly('get', "rule-groups/$id");
        if (!$group->ok()) {
            return $this->pass($group);
        }
        if ($this->isHidden(fget($group->json(), 'data.attributes.title'))) {
            $this->notFound();
        }
        return null;
    }

    private function refuseHiddenTitle($title)
    {
        if ($this->isHidden($title)) {
            throw new GeneralException('"' . config('app.rule_group_suggestions') . '" is reserved for category suggestions. Choose another name.', BaseController::HTTP_CODE_UNPROCESSABLE_ENTITY);
        }
    }

    /** A rule may not be created in, or moved into, the hidden group */
    private function refuseHiddenTarget(Request $request)
    {
        $reserved = new GeneralException('Rules cannot be added to "' . config('app.rule_group_suggestions') . '": it holds category suggestions.', BaseController::HTTP_CODE_UNPROCESSABLE_ENTITY);
        if ($this->isHidden($request->input('rule_group_title'))) {
            throw $reserved;
        }
        $groupId = $request->input('rule_group_id');
        if ($groupId === null || $groupId === '') {
            return;
        }
        $group = $this->firefly('get', 'rule-groups/' . rawurlencode((string)$groupId));
        if ($group->ok() && $this->isHidden(fget($group->json(), 'data.attributes.title'))) {
            throw $reserved;
        }
    }

    private function notFound()
    {
        throw new GeneralException('Not found', BaseController::HTTP_CODE_NOT_FOUND);
    }

    /** The request as it came (method, path, query, body), sent on to Firefly */
    private function forward(Request $request): FireflyResponse
    {
        $slow = (bool)preg_match('#/(test|trigger)$#', $request->path());
        $client = $this->getHttpClient();
        try {
            return ($slow ? $client->timeout(self::SLOW_SECONDS) : $client)->send($request->method(), self::fireflyUrlFor($request), ['body' => $request->getContent()]);
        } catch (ConnectionException $e) {
            if (!$slow) {
                throw $e;
            }
            throw new GeneralException('Firefly took too long to search these dates. Try a shorter date range.', 504, null, $e);
        }
    }

    private function firefly($method, $path): FireflyResponse
    {
        return $this->getHttpClient()->$method(config('app.firefly_url') . "/api/v1/$path");
    }

    private function pass(FireflyResponse $response)
    {
        return response($response->body(), $response->status(), array_filter(['Content-Type' => $response->header('Content-Type')]));
    }
}
