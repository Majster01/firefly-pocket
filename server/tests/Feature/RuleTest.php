<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Rules pages see Firefly's rules as they are, except the suggester's group (RULE_GROUP_SUGGESTIONS).
 */
class RuleTest extends TestCase
{
    private $groups;
    private $rules;
    private $fireflyIsSlow = false;
    private $searchResults = []; // search query => transaction groups

    protected function setUp(): void
    {
        parent::setUp();

        $this->groups = [
            '3' => ['id' => '3', 'type' => 'rule_groups', 'attributes' => ['title' => 'Categorization', 'order' => 1]],
            '4' => ['id' => '4', 'type' => 'rule_groups', 'attributes' => ['title' => 'Suggestions', 'order' => 2]],
            '5' => ['id' => '5', 'type' => 'rule_groups', 'attributes' => ['title' => 'Tags & notes', 'order' => 3]],
        ];
        $this->rules = [
            '10' => $this->makeRule(10, '3', 'Categorization', 'Category: Groceries'),
            '16' => $this->makeRule(16, '4', 'Suggestions', 'Suggest: Food & Drink · Burgerlab'),
            '21' => $this->makeRule(21, '5', 'Tags & notes', 'Tag trips'),
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
            if ($path === 'rule-groups' && $method === 'GET') {
                return Http::response($this->page(array_values($this->groups)));
            }
            if ($path === 'rules' && $method === 'GET') {
                return Http::response($this->page(array_values($this->rules)));
            }
            if (in_array($path, ['rule-groups', 'rules']) && $method === 'POST') {
                return Http::response(['data' => ['id' => '99', 'attributes' => $request->data()]]);
            }
            if (preg_match('#^rule-groups/(\d+)(/rules|/test|/trigger)?$#', $path, $matches)) {
                $group = $this->groups[$matches[1]] ?? null;
                if (!$group) {
                    // Like Firefly 6.6.6: an id that does not exist answers 401, not 404
                    return Http::response(['message' => 'Unauthenticated.'], 401);
                }
                return match ($matches[2] ?? '') {
                    '/rules' => Http::response($this->page(array_values(array_filter($this->rules, fn($rule) => $rule['attributes']['rule_group_id'] === $matches[1])))),
                    '/test' => Http::response($this->page([])),
                    '/trigger' => Http::response(null, 204),
                    default => match ($method) {
                        'GET', 'PUT' => Http::response(['data' => $group]),
                        'DELETE' => Http::response(null, 204),
                    },
                };
            }
            if ($path === 'search/transactions') {
                parse_str((string)parse_url($request->url(), PHP_URL_QUERY), $query);
                return Http::response($this->page($this->searchResults[$query['query']] ?? []));
            }
            if (preg_match('#^rules/(\d+)(/test|/trigger)?$#', $path, $matches)) {
                $rule = $this->rules[$matches[1]] ?? null;
                if (!$rule) {
                    return Http::response(['message' => 'Unauthenticated.'], 401);
                }
                return match ($matches[2] ?? '') {
                    '/test' => $this->fireflyIsSlow ? throw new ConnectionException('cURL error 28: Operation timed out') : Http::response($this->page([['id' => '285', 'type' => 'transactions', 'attributes' => ['transactions' => []]]])),
                    '/trigger' => Http::response(null, 204),
                    default => match ($method) {
                        'GET', 'PUT' => Http::response(['data' => $rule]),
                        'DELETE' => Http::response(null, 204),
                    },
                };
            }

            return Http::response(['message' => "Unexpected $method $path"], 500);
        });
    }

    private function makeRule($id, $groupId, $groupTitle, $title)
    {
        return [
            'id' => (string)$id,
            'type' => 'rules',
            'attributes' => [
                'rule_group_id' => $groupId,
                'rule_group_title' => $groupTitle,
                'title' => $title,
                'triggers' => [['type' => 'description_contains', 'value' => 'lidl']],
                'actions' => [['type' => 'set_category', 'value' => 'Groceries']],
            ],
        ];
    }

    private function page($data)
    {
        return ['data' => $data, 'meta' => ['pagination' => ['total' => count($data), 'count' => count($data), 'per_page' => 50, 'current_page' => 1, 'total_pages' => 1]]];
    }

    private function headers()
    {
        return ['Authorization' => 'Bearer test-token'];
    }

    private function sent($method, $path)
    {
        return collect(Http::recorded())
            ->map(fn($pair) => $pair[0])
            ->filter(fn($request) => $request->method() === $method && preg_match("#/api/v1/$path$#", parse_url($request->url(), PHP_URL_PATH)))
            ->values();
    }

    // ---------------------------

    public function test_lists_leave_out_the_suggestions_group_and_its_rules()
    {
        $groups = $this->getJson('api/rule-groups', $this->headers());
        $groups->assertOk();
        $this->assertEquals(['3', '5'], $groups->json('data.*.id'));
        $this->assertEquals(2, $groups->json('meta.pagination.total'));
        $this->assertEquals(2, $groups->json('meta.pagination.count'));

        $rules = $this->getJson('api/rules', $this->headers());
        $rules->assertOk();
        $this->assertEquals(['10', '21'], $rules->json('data.*.id'));
        $this->assertEquals(2, $rules->json('meta.pagination.total'));
    }

    public function test_other_groups_and_rules_are_read_as_firefly_sends_them()
    {
        $this->getJson('api/rule-groups/3', $this->headers())->assertOk()->assertJsonPath('data.attributes.title', 'Categorization');
        $this->getJson('api/rule-groups/3/rules', $this->headers())->assertOk()->assertJsonPath('data.0.id', '10');
        $this->getJson('api/rules/21', $this->headers())->assertOk()->assertJsonPath('data.attributes.title', 'Tag trips');
        $this->getJson('api/rules/21/test?start=2026-08-01&end=2026-10-31', $this->headers())->assertOk()->assertJsonPath('data.0.id', '285');
        $this->assertStringContainsString('start=2026-08-01', (string)$this->sent('GET', 'rules/21/test')->first()->url());
    }

    private function group($id, $date)
    {
        return ['id' => (string)$id, 'type' => 'transactions', 'attributes' => ['transactions' => [['transaction_journal_id' => (string)($id * 10), 'date' => $date, 'description' => "Transaction $id"]]]];
    }

    public function test_conditions_that_are_not_saved_are_tested_with_one_search_when_all_must_match()
    {
        $query = 'description_contains:"lidl" -has_any_category:true date_after:"2026-08-01" date_before:"2026-10-31"';
        $this->searchResults[$query] = [$this->group(1, '2026-09-01T00:00:00+02:00'), $this->group(2, '2026-10-01T00:00:00+02:00')];

        $response = $this->postJson('api/rules/test', [
            'strict' => true,
            'start' => '2026-08-01',
            'end' => '2026-10-31',
            'triggers' => [
                ['type' => 'description_contains', 'value' => 'lidl', 'prohibited' => false, 'active' => true],
                ['type' => 'has_any_category', 'value' => 'true', 'prohibited' => true, 'active' => true],
                ['type' => 'amount_more', 'value' => '5', 'prohibited' => false, 'active' => false],
            ],
        ], $this->headers());

        $response->assertOk()->assertJsonPath('meta.truncated', false);
        $this->assertEquals(['2', '1'], $response->json('data.*.id'), 'newest first');
        $this->assertCount(1, $this->sent('GET', 'search/transactions'));
    }

    public function test_conditions_that_are_not_saved_are_tested_one_search_each_when_any_may_match()
    {
        $this->searchResults['description_contains:"lidl"'] = [$this->group(1, '2026-09-01T00:00:00+02:00'), $this->group(2, '2026-10-01T00:00:00+02:00')];
        $this->searchResults['description_contains:"spar \\"bio\\""'] = [$this->group(2, '2026-10-01T00:00:00+02:00'), $this->group(3, '2026-10-05T00:00:00+02:00')];

        $response = $this->postJson('api/rules/test', [
            'strict' => false,
            'triggers' => [
                ['type' => 'description_contains', 'value' => 'lidl'],
                ['type' => 'description_contains', 'value' => 'spar "bio"'],
            ],
        ], $this->headers());

        $response->assertOk();
        $this->assertEquals(['3', '2', '1'], $response->json('data.*.id'), 'a transaction both conditions find is listed once');
        $this->assertCount(2, $this->sent('GET', 'search/transactions'));
    }

    public function test_testing_conditions_needs_a_condition_that_is_on_and_is_not_a_change()
    {
        $this->postJson('api/rules/test', ['triggers' => [['type' => 'description_contains', 'value' => 'lidl', 'active' => false]]], $this->headers())->assertStatus(422);
        $this->postJson('api/rules/test', ['triggers' => [['type' => 'description_contains" OR "x', 'value' => 'lidl']]], $this->headers())->assertStatus(422);
        $this->assertCount(0, $this->sent('GET', 'search/transactions'));

        $this->postJson('api/rules/test', ['triggers' => [['type' => 'description_contains', 'value' => 'lidl']]], $this->headers())->assertOk();
        $this->assertCount(0, $this->sent('POST', 'rules/test'), 'nothing is sent to Firefly as it is');
    }

    public function test_testing_a_rule_says_so_when_firefly_takes_too_long()
    {
        $this->fireflyIsSlow = true;
        $this->getJson('api/rules/21/test?start=2026-08-01&end=2026-10-31', $this->headers())
            ->assertStatus(504)
            ->assertJsonPath('message', 'Firefly took too long to search these dates. Try a shorter date range.');
    }

    public function test_the_suggestions_group_and_its_rules_cannot_be_opened()
    {
        $this->getJson('api/rule-groups/4', $this->headers())->assertNotFound();
        $this->getJson('api/rule-groups/4/rules', $this->headers())->assertNotFound();
        $this->getJson('api/rule-groups/4/test', $this->headers())->assertNotFound();
        $this->getJson('api/rules/16', $this->headers())->assertNotFound();
        $this->getJson('api/rules/16/test', $this->headers())->assertNotFound();
    }

    public function test_the_suggestions_group_and_its_rules_cannot_be_changed()
    {
        $this->putJson('api/rule-groups/4', ['title' => 'Mine now'], $this->headers())->assertNotFound();
        $this->deleteJson('api/rule-groups/4', [], $this->headers())->assertNotFound();
        $this->postJson('api/rule-groups/4/trigger', [], $this->headers())->assertNotFound();
        $this->putJson('api/rules/16', ['title' => 'Mine now'], $this->headers())->assertNotFound();
        $this->deleteJson('api/rules/16', [], $this->headers())->assertNotFound();
        $this->postJson('api/rules/16/trigger', [], $this->headers())->assertNotFound();

        $this->assertCount(0, $this->sent('PUT', '.*'));
        $this->assertCount(0, $this->sent('DELETE', '.*'));
        $this->assertCount(0, $this->sent('POST', '.*'));
    }

    public function test_no_rule_can_be_put_into_the_suggestions_group_and_no_group_can_take_its_name()
    {
        $this->postJson('api/rules', ['title' => 'Sneaky', 'rule_group_id' => '4'], $this->headers())->assertStatus(422);
        $this->postJson('api/rules', ['title' => 'Sneaky', 'rule_group_title' => 'suggestions'], $this->headers())->assertStatus(422);
        $this->putJson('api/rules/21', ['rule_group_id' => 4], $this->headers())->assertStatus(422);
        $this->postJson('api/rule-groups', ['title' => 'Suggestions'], $this->headers())->assertStatus(422);
        $this->putJson('api/rule-groups/5', ['title' => ' Suggestions '], $this->headers())->assertStatus(422);

        $this->assertCount(0, $this->sent('POST', '(rules|rule-groups)'));
        $this->assertCount(0, $this->sent('PUT', '.*'));
    }

    public function test_changes_to_other_groups_and_rules_reach_firefly_unchanged()
    {
        $body = ['title' => 'Coffee', 'rule_group_id' => '5', 'trigger' => 'store-journal', 'triggers' => [['type' => 'description_contains', 'value' => 'cafe']], 'actions' => [['type' => 'set_category', 'value' => 'Food & Drink']]];
        $this->postJson('api/rules', $body, $this->headers())->assertOk()->assertJsonPath('data.id', '99');
        $this->assertEquals($body, json_decode($this->sent('POST', 'rules')->first()->body(), true));

        $this->putJson('api/rules/21', ['active' => false], $this->headers())->assertOk();
        $this->assertEquals(['active' => false], json_decode($this->sent('PUT', 'rules/21')->first()->body(), true));

        $this->deleteJson('api/rules/21', [], $this->headers())->assertNoContent();
        $this->postJson('api/rules/21/trigger?start=2026-10-01&end=2026-10-31', [], $this->headers())->assertNoContent();
        $this->assertStringContainsString('end=2026-10-31', (string)$this->sent('POST', 'rules/21/trigger')->first()->url());

        $this->postJson('api/rule-groups', ['title' => 'Trips'], $this->headers())->assertOk();
        $this->putJson('api/rule-groups/5', ['order' => 1], $this->headers())->assertOk();
        $this->deleteJson('api/rule-groups/5', [], $this->headers())->assertNoContent();
    }

    public function test_a_batch_hides_the_group_too_and_reads_firefly_once_per_path()
    {
        $response = $this->postJson('api/batch', ['paths' => ['rule-groups?limit=100&page=1', 'rule-groups/3/rules?limit=100&page=1', 'rule-groups/4/rules?limit=100&page=1']], $this->headers());

        $response->assertOk();
        $this->assertEquals(['3', '5'], $response->json('data.rule-groups?limit=100&page=1.body.data.*.id'));
        $this->assertEquals('10', $response->json('data.rule-groups/3/rules?limit=100&page=1.body.data.0.id'));
        $this->assertEquals(404, $response->json('data.rule-groups/4/rules?limit=100&page=1.status'));
        // The controllers' own GET was answered from the batch's parallel prefetch
        $this->assertCount(1, $this->sent('GET', 'rule-groups'));
        $this->assertCount(1, $this->sent('GET', 'rule-groups/3/rules'));
    }

    public function test_a_rule_that_does_not_exist_answers_like_firefly()
    {
        // The web app asks whether the token still works and then says the rule is gone
        $this->putJson('api/rules/404', ['active' => false], $this->headers())->assertUnauthorized();
        $this->assertCount(0, $this->sent('PUT', '.*'));
    }
}
