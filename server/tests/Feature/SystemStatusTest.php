<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemStatusTest extends TestCase
{
    private $token = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.importer_api_url' => 'http://importer.test/api/fireflyx/v1', 'app.importer_api_token' => 'importer-secret']);
    }

    private function headers($token = null)
    {
        return ['Authorization' => 'Bearer ' . ($token ?? $this->token)];
    }

    /** Firefly answers the user check; the importer answers $importer. */
    private function fake($importer)
    {
        Http::fake(function ($request) use ($importer) {
            if (!str_starts_with($request->url(), 'http://importer.test/')) {
                $authorization = $request->header('Authorization')[0] ?? $request->header('authorization')[0] ?? '';
                return $authorization === 'Bearer test-token' ? Http::response(['data' => ['id' => '1']]) : Http::response(['message' => 'Unauthenticated.'], 401);
            }
            return $importer($request);
        });
    }

    private function importerRequests()
    {
        return collect(Http::recorded())->map(fn($pair) => $pair[0])->filter(fn($request) => str_starts_with($request->url(), 'http://importer.test/'))->values();
    }

    public function test_the_status_is_what_the_importer_collected()
    {
        $this->fake(fn() => Http::response(['data' => ['window_days' => 14, 'imports' => ['totals' => ['runs' => 3]], 'suggester' => ['available' => true]]]));

        $this->getJson('api/system-status?days=14', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.imports.totals.runs', 3)
            ->assertJsonPath('data.suggester.available', true);

        $request = $this->importerRequests()->first();
        $this->assertSame('Bearer importer-secret', $request->header('Authorization')[0]);
        $this->assertStringEndsWith('/status?days=14', $request->url());
    }

    public function test_thirty_days_unless_asked_otherwise()
    {
        $this->fake(fn() => Http::response(['data' => []]));

        $this->getJson('api/system-status', $this->headers())->assertOk();

        $this->assertStringEndsWith('/status?days=30', $this->importerRequests()->first()->url());
    }

    public function test_a_bad_window_never_reaches_the_importer()
    {
        $this->fake(fn() => Http::response(['data' => []]));

        foreach (['0', '366', 'x', '-1'] as $days) {
            $this->getJson('api/system-status?days=' . $days, $this->headers())->assertStatus(422);
        }

        $this->assertCount(0, $this->importerRequests());
    }

    public function test_needs_a_pico_user_and_never_reaches_the_importer_without_one()
    {
        $this->fake(fn() => Http::response(['data' => []]));

        $this->getJson('api/system-status', $this->headers('wrong'))->assertUnauthorized();

        $this->assertCount(0, $this->importerRequests());
    }

    public function test_an_importer_that_is_down_says_so()
    {
        $this->fake(function () {
            throw new ConnectionException('refused');
        });

        $this->getJson('api/system-status', $this->headers())->assertStatus(500)->assertJsonPath('message', 'The importer is not reachable');
    }

    public function test_importer_errors_reach_the_user()
    {
        $this->fake(fn() => Http::response(['error' => 'days must be a number from 1 to 365'], 422));

        $this->getJson('api/system-status', $this->headers())->assertStatus(422)->assertJsonPath('message', 'days must be a number from 1 to 365');
    }

    public function test_the_errors_are_what_the_importer_listed()
    {
        $this->fake(fn() => Http::response(['data' => ['total' => 2, 'items' => []]]));

        $this->getJson('api/system-status/errors?from=2026-10-29&to=2026-10-29&connection=nlb&source=nightly&q=iban&page=2&limit=20&other=x', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $request = $this->importerRequests()->first();
        $this->assertSame('Bearer importer-secret', $request->header('Authorization')[0]);
        $this->assertStringEndsWith('/errors?from=2026-10-29&to=2026-10-29&connection=nlb&source=nightly&q=iban&page=2&limit=20', $request->url(), 'only the importer\'s own parameters are passed on');
    }

    public function test_the_errors_are_asked_without_a_filter()
    {
        $this->fake(fn() => Http::response(['data' => []]));

        $this->getJson('api/system-status/errors', $this->headers())->assertOk();

        $this->assertStringEndsWith('/errors', $this->importerRequests()->first()->url());
    }

    public function test_a_range_the_importer_refuses_reaches_the_user()
    {
        $this->fake(fn() => Http::response(['error' => 'from and to must be days, as 2026-11-09'], 422));

        $this->getJson('api/system-status/errors?from=x', $this->headers())->assertStatus(422)->assertJsonPath('message', 'from and to must be days, as 2026-11-09');
    }

    public function test_the_errors_need_a_pico_user_too()
    {
        $this->fake(fn() => Http::response(['data' => []]));

        $this->getJson('api/system-status/errors', $this->headers('wrong'))->assertUnauthorized();

        $this->assertCount(0, $this->importerRequests());
    }
}
