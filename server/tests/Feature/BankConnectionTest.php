<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BankConnectionTest extends TestCase
{
    private $token = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.importer_api_url' => 'http://importer.test/api/fireflyx/v1', 'app.importer_api_token' => 'importer-secret']);

        Http::fake(function ($request) {
            if (!str_starts_with($request->url(), 'http://importer.test/')) {
                // Firefly: only the user check
                $authorization = $request->header('Authorization')[0] ?? $request->header('authorization')[0] ?? '';
                return $authorization === 'Bearer test-token' ? Http::response(['data' => ['id' => '1']]) : Http::response(['message' => 'Unauthenticated.'], 401);
            }
            $path = parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            if ($method === 'GET' && $path === '/api/fireflyx/v1/banks') {
                return Http::response(['data' => [['name' => 'NLB', 'country' => 'SI', 'logo' => null, 'max_consent_days' => 180, 'psu_types' => ['personal']]]]);
            }
            if ($method === 'POST' && $path === '/api/fireflyx/v1/connections') {
                return Http::response(['data' => ['name' => 'nlb', 'auth_url' => 'https://tilisy.example/auth']]);
            }
            if ($method === 'GET' && $path === '/api/fireflyx/v1/connections/nlb') {
                return Http::response(['data' => ['name' => 'nlb', 'status' => 'needs_mapping', 'accounts' => []]]);
            }
            if ($method === 'PUT' && $path === '/api/fireflyx/v1/connections/nlb/accounts') {
                return Http::response(['data' => ['name' => 'nlb', 'status' => 'active']]);
            }
            if ($method === 'POST' && $path === '/api/fireflyx/v1/connections/nlb/imports') {
                return Http::response(['data' => ['id' => 5, 'status' => 'queued', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']], 202);
            }
            if ($method === 'POST' && $path === '/api/fireflyx/v1/connections/busy/imports') {
                return Http::response(['error' => 'Another manual import is still running'], 409);
            }
            if ($method === 'POST' && $path === '/api/fireflyx/v1/connections/unknown/reauth') {
                return Http::response(['error' => 'No query results'], 404);
            }
            return Http::response(['error' => 'unexpected ' . $method . ' ' . $path], 500);
        });
    }

    private function headers($token = null)
    {
        return ['Authorization' => 'Bearer ' . ($token ?? $this->token)];
    }

    private function importerRequests()
    {
        return collect(Http::recorded())->map(fn($pair) => $pair[0])->filter(fn($request) => str_starts_with($request->url(), 'http://importer.test/'))->values();
    }

    public function test_banks_are_listed_for_a_country()
    {
        $this->getJson('api/bank-connections/banks?country=SI', $this->headers())->assertOk()->assertJsonPath('data.0.name', 'NLB');

        $request = $this->importerRequests()->first();
        $this->assertSame('Bearer importer-secret', $request->header('Authorization')[0]);
        $this->assertStringEndsWith('/banks?country=SI', $request->url());
    }

    public function test_create_starts_the_consent_and_asks_the_importer_to_return_to_the_list_on_failure()
    {
        $this->postJson('api/bank-connections', ['country' => 'SI', 'bank' => 'NLB', 'psu_type' => 'personal'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.auth_url', 'https://tilisy.example/auth');

        $this->assertEquals(
            ['country' => 'SI', 'bank' => 'NLB', 'psu_type' => 'personal', 'return_path' => '/bank-connections/list'],
            $this->importerRequests()->first()->data()
        );
    }

    public function test_accounts_are_mapped_through_to_the_importer()
    {
        $accounts = [['id' => 3, 'firefly_account_id' => 7], ['id' => 4, 'firefly_account_id' => null]];

        $this->putJson('api/bank-connections/nlb/accounts', ['accounts' => $accounts, 'import_since' => '2026-10-01'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(['accounts' => $accounts, 'import_since' => '2026-10-01'], $this->importerRequests()->first()->data());
    }

    public function test_an_import_is_started_over_the_chosen_time_frame()
    {
        $this->postJson('api/bank-connections/nlb/import', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'ignored' => 'x'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'queued');

        $this->assertSame(['date_from' => '2026-09-01', 'date_to' => '2026-09-30'], $this->importerRequests()->first()->data());
    }

    public function test_an_import_the_importer_refuses_says_why()
    {
        $this->postJson('api/bank-connections/busy/import', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Another manual import is still running');
    }

    public function test_one_connection_can_be_read()
    {
        $this->getJson('api/bank-connections/nlb', $this->headers())->assertOk()->assertJsonPath('data.status', 'needs_mapping');
    }

    public function test_everything_needs_a_pico_user_and_never_reaches_the_importer_without_one()
    {
        $this->getJson('api/bank-connections/banks?country=SI', $this->headers('wrong'))->assertUnauthorized();
        $this->postJson('api/bank-connections', ['country' => 'SI', 'bank' => 'NLB'], $this->headers('wrong'))->assertUnauthorized();
        $this->putJson('api/bank-connections/nlb/accounts', ['accounts' => []], $this->headers('wrong'))->assertUnauthorized();
        $this->postJson('api/bank-connections/nlb/import', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'], $this->headers('wrong'))->assertUnauthorized();

        $this->assertCount(0, $this->importerRequests());
    }

    public function test_importer_errors_reach_the_user()
    {
        $this->postJson('api/bank-connections/unknown/reauthenticate', [], $this->headers())->assertNotFound();
    }
}
