<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RuleSuggestionTest extends TestCase
{
    private $token = 'test-token';

    private $rules;
    private $transactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Firefly returns rule descriptions HTML-escaped
        $metadata = htmlspecialchars(json_encode([
            'v' => 1,
            'transactions' => [['transaction_id' => 285, 'journal_id' => 285], ['transaction_id' => 300, 'journal_id' => 300]],
            'category' => 'Food & Drink',
            'confidence' => 0.86,
            'low_confidence' => false,
            'basis' => 'description',
            'company' => 'Hoodburger',
            'reason' => "Card purchase at a burger restaurant, it's lunch",
        ]), ENT_QUOTES);

        $this->rules = [
            $this->makeRule(1, 3, 'Categorization', 'Stop: already categorized', 1, [['type' => 'has_any_category', 'value' => 'true']]),
            $this->makeRule(10, 3, 'Categorization', 'Category: Food & Drink', 2, [['type' => 'description_contains', 'value' => 'mcdonalds']]),
            $this->makeRule(13, 3, 'Categorization', 'Category: Shopping', 4, [['type' => 'description_contains', 'value' => 'decathlon']]),
            $this->makeRule(12, 3, 'Categorization', 'Category: Bills', 3, [['type' => 'description_contains', 'value' => 'a1 slovenija']]),
            $this->makeRule(16, 4, 'Suggestions', 'Suggest: Food & Drink · Hoodburger', 1, [['type' => 'description_contains', 'value' => 'HOODBURGER']], "pico-suggestion:v1 $metadata"),
            $this->makeRule(19, 4, 'Suggestions', 'A manual rule', 2, [['type' => 'description_contains', 'value' => 'lidl']], 'Not a suggestion'),
            $this->makeRule(20, 4, 'Suggestions', 'Suggest: from the future', 3, [['type' => 'description_contains', 'value' => 'spar']], 'pico-suggestion:v1 {&quot;v&quot;: 2}'),
        ];

        $this->transactions = [
            285 => [$this->makeSplit(285, 'HOODBURGER LJ-RUDNIK', null)],
            // Split transaction: only journal 300 is covered by the suggestion
            300 => [$this->makeSplit(300, 'Hoodburger and cinema', null), $this->makeSplit(301, 'Cinema', null)],
            400 => [$this->makeSplit(400, 'HOODBURGER BTC', 'Dining')],
            500 => [$this->makeSplit(500, 'LIDL', null)],
        ];

        Http::fake(function ($request) {
            $authorization = $request->header('Authorization')[0] ?? $request->header('authorization')[0] ?? '';
            if ($authorization !== 'Bearer test-token') {
                return Http::response(['message' => 'Unauthenticated.'], 401);
            }

            $path = preg_replace('#^.*/api/v1/#', '', parse_url($request->url(), PHP_URL_PATH));
            $method = $request->method();

            if ($path === 'about/user') {
                return Http::response(['data' => ['id' => '1']]);
            }
            if ($path === 'rule-groups') {
                return Http::response($this->page([
                    ['id' => '3', 'attributes' => ['title' => 'Categorization']],
                    ['id' => '4', 'attributes' => ['title' => 'Suggestions']],
                ]));
            }
            if (preg_match('#^rule-groups/(\d+)/rules$#', $path, $matches)) {
                return Http::response($this->page(array_values(array_filter($this->rules, fn($rule) => $rule['attributes']['rule_group_id'] === $matches[1]))));
            }
            if ($path === 'rules' && $method === 'POST') {
                return Http::response(['data' => ['id' => '50', 'attributes' => $request->data()]]);
            }
            if (preg_match('#^rules/(\d+)$#', $path, $matches)) {
                $rule = collect($this->rules)->firstWhere('id', $matches[1]);
                return match ($method) {
                    'GET' => $rule ? Http::response(['data' => $rule]) : Http::response(['message' => 'Not found'], 404),
                    'PUT' => Http::response(['data' => $rule]),
                    'DELETE' => Http::response(null, 204),
                };
            }
            if (preg_match('#^transactions/(\d+)$#', $path, $matches)) {
                $splits = $this->transactions[$matches[1]] ?? null;
                if (!$splits) {
                    return Http::response(['message' => 'Not found'], 404);
                }
                return Http::response(['data' => ['id' => $matches[1], 'attributes' => ['transactions' => $splits]]]);
            }
            if ($path === 'search/transactions') {
                return Http::response($this->search($request->data()));
            }

            return Http::response(['message' => "Unexpected $method $path"], 500);
        });
    }

    private function makeRule($id, $groupId, $groupTitle, $title, $order, $triggers, $description = null)
    {
        return [
            'id' => (string)$id,
            'type' => 'rules',
            'attributes' => [
                'rule_group_id' => (string)$groupId,
                'rule_group_title' => $groupTitle,
                'title' => $title,
                'description' => $description,
                'order' => $order,
                'triggers' => array_map(fn($trigger) => $trigger + ['id' => '1', 'active' => true, 'prohibited' => false, 'stop_processing' => true], $triggers),
            ],
        ];
    }

    private function makeSplit($journalId, $description, $category)
    {
        return [
            'transaction_journal_id' => (string)$journalId,
            'description' => $description,
            'amount' => '19.40',
            'date' => '2026-10-12T00:00:00+02:00',
            'type' => 'withdrawal',
            'destination_name' => '(unknown destination)',
            'category_name' => $category,
            'currency_code' => 'EUR',
        ];
    }

    private function page($data)
    {
        return ['data' => $data, 'meta' => ['pagination' => ['total' => count($data), 'total_pages' => 1]]];
    }

    // Only description_contains is needed here. Like Firefly, the result holds only the matching splits.
    private function search($query)
    {
        if (($query['page'] ?? 1) > 1) {
            return $this->page([]);
        }
        preg_match('#^description_contains:"(.*)"$#', $query['query'], $matches);
        $groups = [];
        foreach ($this->transactions as $id => $splits) {
            $splits = array_values(array_filter($splits, fn($split) => stripos($split['description'], $matches[1]) !== false));
            if ($splits) {
                $groups[] = ['id' => (string)$id, 'attributes' => ['transactions' => $splits]];
            }
        }
        return $this->page($groups);
    }

    private function headers($token = null)
    {
        return ['Authorization' => 'Bearer ' . ($token ?? $this->token)];
    }

    private function recorded($method, $pathPattern)
    {
        return collect(Http::recorded())
            ->map(fn($pair) => $pair[0])
            ->filter(fn($request) => $request->method() === $method && preg_match("#/api/v1/$pathPattern$#", parse_url($request->url(), PHP_URL_PATH)))
            ->values();
    }

    private function categorizedJournals()
    {
        return $this->recorded('PUT', 'transactions/\d+')
            ->flatMap(fn($request) => array_filter($request->data()['transactions'], fn($split) => isset($split['category_name'])))
            ->pluck('category_name', 'transaction_journal_id')
            ->all();
    }

    // ---------------------------

    public function test_list_shows_only_marked_suggestions_with_transactions()
    {
        $response = $this->getJson('api/rule-suggestions', $this->headers());

        $response->assertOk();
        $this->assertEquals(['16'], $response->json('data.*.id'));
        // The HTML-escaped description is decoded before parsing
        $this->assertEquals('Food & Drink', $response->json('data.0.attributes.category'));
        $this->assertEquals("Card purchase at a burger restaurant, it's lunch", $response->json('data.0.attributes.reason'));
        $this->assertEquals([['type' => 'description_contains', 'value' => 'HOODBURGER']], $response->json('data.0.attributes.triggers'));
        $this->assertEquals('HOODBURGER LJ-RUDNIK', $response->json('data.0.attributes.transactions.0.description'));
        $this->assertEquals('19.40', $response->json('data.0.attributes.transactions.0.amount'));
        $this->assertEquals('Hoodburger and cinema', $response->json('data.0.attributes.transactions.1.description'));
    }

    public function test_get_one_hides_rules_that_are_not_suggestions()
    {
        $this->getJson('api/rule-suggestions/16', $this->headers())->assertOk()->assertJsonPath('data.id', '16');
        $this->getJson('api/rule-suggestions/10', $this->headers())->assertNotFound();
        $this->getJson('api/rule-suggestions/19', $this->headers())->assertNotFound();
        $this->getJson('api/rule-suggestions/20', $this->headers())->assertNotFound();
    }

    public function test_preview_counts_uncategorized_and_categorized_matches()
    {
        $response = $this->postJson('api/rule-suggestions/preview', ['triggers' => [['type' => 'description_contains', 'value' => 'hoodburger']]], $this->headers());

        $response->assertOk();
        $this->assertEquals(['uncategorized_count' => 2, 'categorized_count' => 1], $response->json('data'));
    }

    public function test_approve_merges_only_new_triggers_into_the_existing_rule()
    {
        $triggers = [
            ['type' => 'description_contains', 'value' => 'HOODBURGER'],
            ['type' => 'description_contains', 'value' => 'hoodburger '],
            ['type' => 'description_contains', 'value' => 'McDonalds'],
        ];
        $response = $this->postJson('api/rule-suggestions/16/approve', ['category' => 'Food & Drink', 'triggers' => $triggers], $this->headers());

        $response->assertOk();
        $this->assertEquals(['rule_id' => '10', 'is_rule_created' => false, 'triggers_added_count' => 1, 'transactions_count' => 2], $response->json('data'));

        $updates = $this->recorded('PUT', 'rules/10');
        $this->assertCount(1, $updates);
        $this->assertEquals([['mcdonalds', true], ['HOODBURGER', true]], array_map(fn($trigger) => [$trigger['value'], $trigger['stop_processing']], $updates[0]->data()['triggers']));
        $this->assertCount(0, $this->recorded('POST', 'rules'));
        $this->assertCount(1, $this->recorded('DELETE', 'rules/16'));
    }

    public function test_approve_categorizes_only_uncategorized_transactions_and_never_runs_the_rule_group()
    {
        $this->postJson('api/rule-suggestions/16/approve', ['category' => 'Food & Drink', 'triggers' => [['type' => 'description_contains', 'value' => 'hoodburger']]], $this->headers())->assertOk();

        // 400 is already categorized ("Dining") and must stay as it is
        $this->assertEquals(['285' => 'Food & Drink', '300' => 'Food & Drink'], $this->categorizedJournals());
        // Firefly's API rule-group trigger ignores the "Stop: already categorized" guard
        $this->assertCount(0, $this->recorded('POST', 'rule-groups/\d+/trigger'));
        $this->assertCount(0, $this->recorded('POST', 'rules/\d+/trigger'));
    }

    public function test_approve_keeps_every_split_of_a_split_transaction()
    {
        $this->postJson('api/rule-suggestions/16/approve', ['category' => 'Food & Drink', 'triggers' => [['type' => 'description_contains', 'value' => 'HOODBURGER']]], $this->headers())->assertOk();

        $update = $this->recorded('PUT', 'transactions/300')->first();
        $this->assertEquals([
            ['transaction_journal_id' => '300', 'category_name' => 'Food & Drink'],
            ['transaction_journal_id' => '301'],
        ], $update->data()['transactions']);
    }

    public function test_approve_creates_a_missing_rule_above_the_generic_tail()
    {
        $response = $this->postJson('api/rule-suggestions/16/approve', ['category' => 'Travel', 'triggers' => [['type' => 'description_contains', 'value' => 'HOODBURGER']]], $this->headers());

        $response->assertOk();
        $this->assertEquals(['rule_id' => '50', 'is_rule_created' => true, 'triggers_added_count' => 1, 'transactions_count' => 2], $response->json('data'));
        $body = $this->recorded('POST', 'rules')->first()->data();
        $this->assertEquals('Category: Travel', $body['title']);
        $this->assertEquals('3', $body['rule_group_id']);
        // Above "Category: Bills" (order 3), the first rule of the generic tail
        $this->assertEquals(3, $body['order']);
        $this->assertEquals(['store-journal', true, false, true], [$body['trigger'], $body['active'], $body['strict'], $body['stop_processing']]);
        $this->assertEquals([['type' => 'set_category', 'value' => 'Travel', 'active' => true, 'stop_processing' => false]], $body['actions']);
    }

    public function test_approve_appends_a_missing_last_tail_rule_at_the_end()
    {
        $this->postJson('api/rule-suggestions/16/approve', ['category' => 'Other', 'triggers' => [['type' => 'description_contains', 'value' => 'HOODBURGER']]], $this->headers())->assertOk();

        $this->assertArrayNotHasKey('order', $this->recorded('POST', 'rules')->first()->data());
    }

    public function test_approve_validates_the_triggers()
    {
        $this->postJson('api/rule-suggestions/16/approve', ['category' => 'Food & Drink', 'triggers' => []], $this->headers())->assertStatus(422);
        $this->postJson('api/rule-suggestions/16/approve', ['category' => 'Food & Drink', 'triggers' => [['type' => 'has_any_category', 'value' => 'true']]], $this->headers())->assertStatus(422);

        $this->assertCount(0, $this->recorded('DELETE', 'rules/16'));
    }

    public function test_categorize_sets_the_category_without_writing_a_rule()
    {
        $response = $this->postJson('api/rule-suggestions/16/categorize', ['category' => 'Food & Drink'], $this->headers());

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.transactions_count'));
        $this->assertEquals(['285' => 'Food & Drink', '300' => 'Food & Drink'], $this->categorizedJournals());
        $this->assertCount(0, $this->recorded('POST', 'rules'));
        $this->assertCount(0, $this->recorded('PUT', 'rules/\d+'));
        $this->assertCount(1, $this->recorded('DELETE', 'rules/16'));
    }

    public function test_reject_deletes_only_the_suggestion()
    {
        $this->deleteJson('api/rule-suggestions/16', [], $this->headers())->assertOk();

        $this->assertCount(1, $this->recorded('DELETE', 'rules/16'));
        $this->assertCount(0, $this->recorded('PUT', 'transactions/\d+'));
    }

    public function test_reject_does_not_delete_unmarked_rules()
    {
        $this->deleteJson('api/rule-suggestions/19', [], $this->headers())->assertNotFound();
        $this->deleteJson('api/rule-suggestions/10', [], $this->headers())->assertNotFound();

        $this->assertCount(0, $this->recorded('DELETE', 'rules/\d+'));
    }

    public function test_without_valid_token_fails()
    {
        $this->getJson('api/rule-suggestions', $this->headers('bad-token'))->assertStatus(401);
    }
}
