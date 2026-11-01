<?php

namespace Tests\Feature;

use App\Jobs\WarmUpResponseCache;
use App\Services\BatchReader;
use App\Services\CacheWarmUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BatchAndWarmUpTest extends TestCase
{
    use RefreshDatabase;

    // Token → Firefly user. warm-token is the warm-up's own token of user 1; other-user's token is user 2
    const TOKENS = ['phone' => '1', 'desktop' => '1', 'warm-token' => '1', 'other-user' => '2'];
    const NOTIFY_TOKEN = 'notify-token-notify-token-notify-token';

    private array $calls = [];
    private array $tokensUsed = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::driver('array')->flush();
        Config::set('app.notify_api_token', self::NOTIFY_TOKEN);
        // Off unless a test turns it on, whatever the local .env says
        Config::set('app.warmup_firefly_token', '');
        Config::set('queue.default', 'sync');

        Http::fake(function ($request) {
            $authorization = $request->header('Authorization')[0] ?? $request->header('authorization')[0] ?? '';
            $token = str_replace('Bearer ', '', $authorization);
            if (!isset(self::TOKENS[$token])) {
                return Http::response(['message' => 'Unauthenticated.'], 401);
            }
            $path = preg_replace('#^.*/api/v1/#', '', parse_url($request->url(), PHP_URL_PATH));
            $this->calls[$path] = ($this->calls[$path] ?? 0) + 1;
            $this->tokensUsed[$path][] = $token;

            if ($path === 'about/user') {
                return Http::response(['data' => ['id' => self::TOKENS[$token]]]);
            }
            if ($path === 'insight/broken') {
                return Http::response(['message' => 'Boom'], 500);
            }
            if ($path === 'rule-groups') {
                return Http::response(['data' => [], 'meta' => ['pagination' => ['total_pages' => 1]]]);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return Http::response(['data' => [['id' => '1', 'attributes' => ['path' => $path, 'start' => $query['start'] ?? null, 'call' => $this->calls[$path]]]], 'meta' => (object) []]);
        });
    }

    private function headers($token = 'phone')
    {
        return ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
    }

    private function batch(array $paths, $token = 'phone', $page = '/')
    {
        return $this->postJson('api/batch', ['paths' => $paths, 'page' => $page], $this->headers($token));
    }

    // ------------------------------------------------------------------ batch

    public function test_a_batch_answers_each_read_like_its_own_request()
    {
        $response = $this->batch(['insight/expense/total?start=2026-10-01&end=2026-10-31', 'accounts?limit=100&page=1', 'budget-limits?start=2026-10-01&end=2026-10-31']);

        $response->assertOk();
        $this->assertEquals(200, $response->json('data.insight/expense/total?start=2026-10-01&end=2026-10-31.status'));
        $this->assertEquals('2026-10-01', $response->json('data.insight/expense/total?start=2026-10-01&end=2026-10-31.body.data.0.attributes.start'));
        $this->assertEquals('accounts', $response->json('data.accounts?limit=100&page=1.body.data.0.attributes.path'));
        // An empty object stays an object
        $this->assertStringContainsString('"meta":{}', $response->getContent());
        // One call each: the controllers' own GET was answered from the parallel prefetch
        $this->assertEquals(1, $this->calls['insight/expense/total']);
        $this->assertEquals(1, $this->calls['accounts']);
        $this->assertEquals(1, $this->calls['budget-limits']);
    }

    public function test_batched_reads_are_kept_and_served_singly_too()
    {
        $this->batch(['insight/expense/total?start=2026-10-01&end=2026-10-31']);

        $this->getJson('api/insight/expense/total?start=2026-10-01&end=2026-10-31', $this->headers())->assertHeader('X-Pocket-Cache', 'hit');
        $this->batch(['insight/expense/total?start=2026-10-01&end=2026-10-31'])->assertJsonPath('data.insight/expense/total?start=2026-10-01&end=2026-10-31.status', 200);
        $this->assertEquals(1, $this->calls['insight/expense/total']);
    }

    public function test_each_read_has_its_own_status()
    {
        $response = $this->batch(['insight/broken?start=1', 'profiles', 'accounts']);

        $response->assertOk();
        $this->assertEquals(500, $response->json('data.insight/broken?start=1.status'));
        // Pocket's own data is not a Firefly read
        $this->assertEquals(400, $response->json('data.profiles.status'));
        $this->assertEquals(200, $response->json('data.accounts.status'));
    }

    public function test_reads_pocket_works_out_itself_run_in_the_batch()
    {
        $this->batch(['rule-suggestions'])->assertJsonPath('data.rule-suggestions.status', 200)->assertJsonPath('data.rule-suggestions.body.data', []);
        // Not prefetched as a Firefly path of its own
        $this->assertArrayNotHasKey('rule-suggestions', $this->calls);
    }

    public function test_a_batch_changes_nothing()
    {
        $this->getJson('api/accounts', $this->headers());
        $this->batch(['categories']);

        $this->getJson('api/accounts', $this->headers())->assertHeader('X-Pocket-Cache', 'hit');
    }

    public function test_a_batch_needs_a_good_token_and_sane_paths()
    {
        $this->batch(['accounts'], 'stolen')->assertUnauthorized();
        $this->batch([])->assertStatus(422);
        $this->batch(array_fill(0, BatchReader::MAX_PATHS + 1, 'accounts'))->assertStatus(422);
        $this->postJson('api/batch', ['paths' => [['nested']]], $this->headers())->assertStatus(422);
        $this->calls = [];
        $answers = $this->batch(['../../etc/passwd', 'http://evil.test/x'])->assertOk()->json('data');
        $this->assertEquals(400, $answers['../../etc/passwd']['status']);
        $this->assertEquals(400, $answers['http://evil.test/x']['status']);
        $this->assertSame([], $this->calls);
    }

    public function test_a_batch_is_timed_as_a_whole()
    {
        $timing = $this->batch(['accounts', 'categories'])->headers->get('Server-Timing');

        // about/user, then accounts and categories at once
        $this->assertMatchesRegularExpression('/^total;dur=[\d.]+, upstream;dur=[\d.]+;desc="3 calls"$/', $timing);
    }

    // ---------------------------------------------------------------- warm-up

    private function turnWarmUpOn()
    {
        Config::set('app.warmup_firefly_token', 'warm-token');
        Config::set('queue.default', 'redis');
        Bus::fake();
    }

    public function test_a_change_schedules_one_warm_up()
    {
        $this->turnWarmUpOn();
        $this->postJson('api/transactions', [], $this->headers());
        $this->postJson('api/internal/notify', ['title' => 'Imported', 'body' => '3 new'], ['Authorization' => 'Bearer ' . self::NOTIFY_TOKEN]);

        Bus::assertDispatchedTimes(WarmUpResponseCache::class, 1);
    }

    public function test_no_warm_up_without_its_token_or_a_queue()
    {
        Bus::fake();
        Config::set('queue.default', 'redis');
        $this->postJson('api/transactions', [], $this->headers());
        Config::set('app.warmup_firefly_token', 'warm-token');
        Config::set('queue.default', 'sync');
        $this->postJson('api/transactions', [], $this->headers());

        Bus::assertNotDispatched(WarmUpResponseCache::class);
    }

    public function test_the_warm_up_keeps_what_home_read_for_each_token()
    {
        $this->turnWarmUpOn();
        $this->batch(['insight/expense/total?start=2026-10-01&end=2026-10-31', 'accounts'], 'phone');
        $this->batch(['insight/expense/total?start=2026-10-01&end=2026-10-31', 'insight/broken?start=1'], 'desktop');
        // Not Home: not remembered
        $this->batch(['tags'], 'phone', '/tags');
        $this->postJson('api/internal/notify', ['title' => 'Imported', 'body' => '3 new'], ['Authorization' => 'Bearer ' . self::NOTIFY_TOKEN]);
        $this->calls = [];
        $this->tokensUsed = [];

        $count = app(CacheWarmUp::class)->run(app(BatchReader::class));

        // phone: insight, accounts, rule-suggestions; desktop: insight, rule-suggestions (the broken read was not remembered)
        $this->assertEquals(5, $count);
        $this->assertArrayNotHasKey('tags', $this->calls);
        // Firefly was asked with the warm-up's own token only
        $this->assertEquals(['warm-token'], array_values(array_unique(array_merge(...array_values($this->tokensUsed)))));

        $this->calls = [];
        $this->getJson('api/insight/expense/total?start=2026-10-01&end=2026-10-31', $this->headers('phone'))->assertHeader('X-Pocket-Cache', 'hit');
        $this->getJson('api/accounts', $this->headers('phone'))->assertHeader('X-Pocket-Cache', 'hit');
        $this->getJson('api/insight/expense/total?start=2026-10-01&end=2026-10-31', $this->headers('desktop'))->assertHeader('X-Pocket-Cache', 'hit');
        $this->getJson('api/rule-suggestions', $this->headers('desktop'))->assertOk();
        $this->assertArrayNotHasKey('insight/expense/total', $this->calls);
    }

    public function test_the_warm_up_never_keeps_one_users_data_for_another()
    {
        $this->turnWarmUpOn();
        $this->batch(['accounts'], 'other-user');
        $this->postJson('api/internal/notify', ['title' => 'x', 'body' => 'y'], ['Authorization' => 'Bearer ' . self::NOTIFY_TOKEN]);
        $this->calls = [];

        $this->assertEquals(0, app(CacheWarmUp::class)->run(app(BatchReader::class)));
        $this->assertArrayNotHasKey('accounts', $this->calls);
        $this->getJson('api/accounts', $this->headers('other-user'))->assertHeader('X-Pocket-Cache', 'miss');
    }

    // The queue worker boots only the console kernel: no HTTP request has registered the routes' middleware groups
    public function test_the_warm_up_runs_in_a_worker_that_served_no_request()
    {
        $this->turnWarmUpOn();
        $phone = Request::create('/api/batch', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer phone']);
        $this->app->instance('request', $phone);
        app(CacheWarmUp::class)->remember($phone, ['accounts', 'insight/expense/total?start=2026-10-01&end=2026-10-31']);

        $this->assertEquals(3, app(CacheWarmUp::class)->run(app(BatchReader::class)));
    }

    public function test_the_warm_up_stops_when_firefly_refuses_its_token()
    {
        $this->turnWarmUpOn();
        $this->batch(['accounts'], 'phone');
        Config::set('app.warmup_firefly_token', 'revoked');

        $this->assertEquals(0, app(CacheWarmUp::class)->run(app(BatchReader::class)));
    }
}
