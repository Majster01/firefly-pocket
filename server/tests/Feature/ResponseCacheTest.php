<?php

namespace Tests\Feature;

use App\Services\ResponseCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResponseCacheTest extends TestCase
{
    use RefreshDatabase;

    const TOKENS = ['token-a' => '1', 'token-b' => '1'];
    const WEBHOOK_SECRET = 'firefly-webhook-secret';
    const NOTIFY_TOKEN = 'notify-token-notify-token-notify-token';

    // Firefly calls per path, and what Firefly answers for a path
    private array $calls = [];
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::driver('array')->flush();
        Config::set('app.firefly_webhook_secret', self::WEBHOOK_SECRET);
        Config::set('app.notify_api_token', self::NOTIFY_TOKEN);

        Http::fake(function ($request) {
            $authorization = $request->header('Authorization')[0] ?? $request->header('authorization')[0] ?? '';
            $token = str_replace('Bearer ', '', $authorization);
            if (!isset(self::TOKENS[$token])) {
                return Http::response(['message' => 'Unauthenticated.'], 401);
            }
            $path = preg_replace('#^.*/api/v1/#', '', parse_url($request->url(), PHP_URL_PATH));
            $key = $request->method() . ' ' . $path;
            $this->calls[$key] = ($this->calls[$key] ?? 0) + 1;

            if ($path === 'about/user') {
                return Http::response(['data' => ['id' => self::TOKENS[$token]]]);
            }
            if (isset($this->answers[$key])) {
                return $this->answers[$key];
            }
            return Http::response(['data' => [['id' => '1', 'attributes' => ['name' => 'Call ' . $this->calls[$key]]]]]);
        });
    }

    private function headers($token = 'token-a', $extra = [])
    {
        return ['Authorization' => "Bearer $token", 'Accept' => 'application/json'] + $extra;
    }

    private function calls($key)
    {
        return $this->calls[$key] ?? 0;
    }

    public function test_a_second_read_is_answered_without_firefly()
    {
        $first = $this->getJson('api/insight/expense/total?start=2026-10-01&end=2026-10-31', $this->headers());
        $second = $this->getJson('api/insight/expense/total?start=2026-10-01&end=2026-10-31', $this->headers());

        $first->assertOk()->assertHeader('X-Pocket-Cache', 'miss');
        $second->assertOk()->assertHeader('X-Pocket-Cache', 'hit');
        $this->assertEquals($first->getContent(), $second->getContent());
        $this->assertEquals(1, $this->calls('GET insight/expense/total'));
        // The token is checked once, then kept
        $this->assertEquals(1, $this->calls('GET about/user'));
    }

    public function test_another_query_is_another_entry()
    {
        $this->getJson('api/insight/expense/total?start=2026-10-01&end=2026-10-31', $this->headers());
        $this->getJson('api/insight/expense/total?start=2026-09-01&end=2026-09-30', $this->headers())->assertHeader('X-Pocket-Cache', 'miss');

        $this->assertEquals(2, $this->calls('GET insight/expense/total'));
    }

    public function test_controller_reads_are_cached_too()
    {
        $this->getJson('api/accounts', $this->headers())->assertOk();
        $this->getJson('api/accounts', $this->headers())->assertOk()->assertHeader('X-Pocket-Cache', 'hit');

        $this->assertEquals(1, $this->calls('GET accounts'));
    }

    public function test_a_change_through_pocket_starts_a_new_version()
    {
        $this->getJson('api/transactions', $this->headers())->assertJsonPath('data.0.attributes.name', 'Call 1');
        $this->postJson('api/transactions', ['transactions' => []], $this->headers())->assertOk();

        $this->getJson('api/transactions', $this->headers())->assertHeader('X-Pocket-Cache', 'miss')->assertJsonPath('data.0.attributes.name', 'Call 2');
    }

    public function test_a_change_by_one_token_is_seen_by_another()
    {
        $this->getJson('api/accounts', $this->headers('token-b'));
        $this->putJson('api/accounts/1', ['name' => 'Renamed'], $this->headers('token-a'))->assertOk();

        $this->getJson('api/accounts', $this->headers('token-b'))->assertHeader('X-Pocket-Cache', 'miss');
    }

    public function test_entries_are_per_token()
    {
        $this->getJson('api/accounts', $this->headers('token-a'));
        $this->getJson('api/accounts', $this->headers('token-b'))->assertHeader('X-Pocket-Cache', 'miss');

        $this->assertEquals(2, $this->calls('GET accounts'));
    }

    public function test_a_refused_token_never_gets_a_cached_answer()
    {
        $this->getJson('api/accounts', $this->headers('token-a'))->assertOk();

        $this->getJson('api/accounts', $this->headers('stolen'))->assertUnauthorized();
        $this->getJson('api/accounts')->assertStatus(401);
    }

    public function test_a_change_by_a_refused_token_does_not_empty_the_cache()
    {
        $this->getJson('api/accounts', $this->headers());
        $this->postJson('api/transactions', [], $this->headers('stolen'));
        $this->postJson('api/transactions', []);

        $this->getJson('api/accounts', $this->headers())->assertHeader('X-Pocket-Cache', 'hit');
    }

    public function test_saving_the_home_layout_or_a_preview_keeps_the_cache()
    {
        $this->getJson('api/accounts', $this->headers());
        $profile = $this->getJson('api/profiles', $this->headers())->json('data.0');
        $this->putJson("api/profiles/{$profile['id']}", ['name' => 'Default', 'settings' => ['home' => []]], $this->headers())->assertOk();
        $this->postJson('api/rule-suggestions/preview', ['triggers' => []], $this->headers());

        $this->getJson('api/accounts', $this->headers())->assertHeader('X-Pocket-Cache', 'hit');
    }

    public function test_errors_are_not_kept()
    {
        $this->answers['GET budgets'] = Http::response(['message' => 'Boom'], 500);
        $this->getJson('api/budgets', $this->headers())->assertStatus(500);
        unset($this->answers['GET budgets']);

        $this->getJson('api/budgets', $this->headers())->assertOk()->assertHeader('X-Pocket-Cache', 'miss');
        $this->assertEquals(2, $this->calls('GET budgets'));
    }

    public function test_pocket_own_data_is_not_cached()
    {
        $this->getJson('api/profiles', $this->headers())->assertOk()->assertHeaderMissing('X-Pocket-Cache');
        $this->getJson('api/user', $this->headers())->assertOk()->assertHeaderMissing('X-Pocket-Cache');
    }

    public function test_an_unchanged_answer_is_a_304()
    {
        $first = $this->getJson('api/categories', $this->headers());
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $first->assertHeader('Cache-Control', 'no-cache, private');

        $second = $this->getJson('api/categories', $this->headers(extra: ['If-None-Match' => $etag]));
        $second->assertStatus(304);
        $this->assertSame('', $second->getContent());

        // Changed data: a new tag and the full answer
        $this->postJson('api/categories', ['name' => 'New'], $this->headers());
        $third = $this->getJson('api/categories', $this->headers(extra: ['If-None-Match' => $etag]));
        $third->assertOk();
        $this->assertNotEquals($etag, $third->headers->get('ETag'));
    }

    public function test_the_client_can_ask_for_a_fresh_answer()
    {
        $this->getJson('api/tags', $this->headers());
        $this->getJson('api/tags', $this->headers(extra: ['Cache-Control' => 'no-cache']))->assertHeader('X-Pocket-Cache', 'bypass');
        // ... which replaces the kept one
        $this->getJson('api/tags', $this->headers())->assertHeader('X-Pocket-Cache', 'hit')->assertJsonPath('data.0.attributes.name', 'Call 2');

        $this->assertEquals(2, $this->calls('GET tags'));
    }

    public function test_the_importer_notify_starts_a_new_version()
    {
        $this->getJson('api/accounts', $this->headers());
        $this->postJson('api/internal/notify', ['title' => 'Imported', 'body' => '3 new'], ['Authorization' => 'Bearer wrong-token-wrong-token-wrong-token-12'])->assertUnauthorized();
        $this->getJson('api/accounts', $this->headers())->assertHeader('X-Pocket-Cache', 'hit');

        // Push is not configured here: the cache is emptied all the same
        $this->postJson('api/internal/notify', ['title' => 'Imported', 'body' => '3 new'], ['Authorization' => 'Bearer ' . self::NOTIFY_TOKEN]);
        $this->getJson('api/accounts', $this->headers())->assertHeader('X-Pocket-Cache', 'miss');
    }

    public function test_the_notifications_center_is_neither_kept_nor_a_change_of_firefly()
    {
        $this->getJson('api/accounts', $this->headers());

        $this->getJson('api/notifications', $this->headers())->assertOk()->assertHeaderMissing('X-Pocket-Cache');
        $this->getJson('api/notifications/unread-count', $this->headers())->assertOk()->assertHeaderMissing('X-Pocket-Cache');
        $this->postJson('api/notifications/read-all', [], $this->headers())->assertOk();
        $this->postJson('api/notifications/1/read', [], $this->headers())->assertNotFound();

        $this->getJson('api/accounts', $this->headers())->assertHeader('X-Pocket-Cache', 'hit');
    }

    private function webhook($body, $secret = self::WEBHOOK_SECRET, $timestamp = 1793000000)
    {
        $json = json_encode($body);
        $signature = hash_hmac('sha3-256', "$timestamp.$json", $secret);
        return $this->call('POST', 'api/internal/firefly-webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGNATURE' => "t=$timestamp,v1=$signature"], $json);
    }

    public function test_a_signed_firefly_webhook_starts_a_new_version()
    {
        $this->getJson('api/accounts', $this->headers());
        $this->webhook(['uuid' => 'x', 'trigger' => 'STORE_TRANSACTION', 'content' => []])->assertOk();

        $this->getJson('api/accounts', $this->headers())->assertHeader('X-Pocket-Cache', 'miss');
    }

    public function test_an_unsigned_firefly_webhook_is_refused()
    {
        $this->getJson('api/accounts', $this->headers());
        $this->webhook(['trigger' => 'STORE_TRANSACTION'], 'another-secret')->assertUnauthorized();
        $this->postJson('api/internal/firefly-webhook', ['trigger' => 'STORE_TRANSACTION'])->assertUnauthorized();

        $this->getJson('api/accounts', $this->headers())->assertHeader('X-Pocket-Cache', 'hit');
    }

    public function test_the_firefly_webhook_needs_a_secret()
    {
        Config::set('app.firefly_webhook_secret', '');
        $this->webhook(['trigger' => 'STORE_TRANSACTION'], '')->assertStatus(503);
    }

    public function test_a_lost_version_does_not_bring_back_old_entries()
    {
        $cache = app(ResponseCache::class);
        $version = $cache->version();
        $this->assertGreaterThan(1000, $version);

        $cache->bump();
        Cache::forget(ResponseCache::VERSION_KEY);
        usleep(5000);
        // Started again from the clock: past every version handed out before
        $this->assertGreaterThan($version + 1, $cache->version());
    }

    public function test_the_cache_can_be_switched_off()
    {
        Config::set('app.response_cache', false);
        $this->getJson('api/accounts', $this->headers())->assertHeaderMissing('X-Pocket-Cache');
        $this->getJson('api/accounts', $this->headers());

        $this->assertEquals(2, $this->calls('GET accounts'));
    }

    public function test_nothing_is_kept_past_midnight_in_fireflys_timezone()
    {
        Config::set('app.response_cache_timezone', 'Europe/Ljubljana');
        // 23:30 in Ljubljana (22:30 UTC), then 00:30 there: still the same day in UTC
        $this->travelTo(Carbon::parse('2026-10-31 22:30:00', 'UTC'));
        $this->getJson('api/recurrences', $this->headers());
        $this->getJson('api/recurrences', $this->headers())->assertHeader('X-Pocket-Cache', 'hit');

        $this->travelTo(Carbon::parse('2026-10-31 23:30:00', 'UTC'));
        $this->getJson('api/recurrences', $this->headers())->assertHeader('X-Pocket-Cache', 'miss');
    }

    public function test_the_data_version_moves_with_a_change()
    {
        $first = $this->getJson('api/data-version', $this->headers())->assertOk()->assertHeaderMissing('X-Pocket-Cache');
        $this->assertIsString($first->json('data.version'));
        $this->assertEquals(43200, $first->json('data.ttl'));
        $this->getJson('api/data-version', $this->headers())->assertJsonPath('data.version', $first->json('data.version'));

        $this->postJson('api/transactions', [], $this->headers());
        $this->assertNotEquals($first->json('data.version'), $this->getJson('api/data-version', $this->headers())->json('data.version'));

        $this->getJson('api/data-version', $this->headers('stolen'))->assertUnauthorized();
        Config::set('app.response_cache', false);
        $this->getJson('api/data-version', $this->headers())->assertJsonPath('data.version', null);
    }

    public function test_sync_everything_starts_one_new_version()
    {
        $this->getJson('api/accounts', $this->headers())->assertOk();
        $before = (int) $this->getJson('api/data-version', $this->headers())->json('data.version');

        // Only a token Firefly accepts can empty the cache
        $this->postJson('api/data-version', [], $this->headers('stolen'))->assertUnauthorized();
        $this->postJson('api/data-version', [])->assertUnauthorized();
        $this->getJson('api/accounts', $this->headers())->assertHeader('X-Pocket-Cache', 'hit');

        $renewed = $this->postJson('api/data-version', [], $this->headers())->assertOk()->assertHeaderMissing('X-Pocket-Cache');
        // One step, not two: the middleware leaves this route's version to the controller
        $this->assertSame((string) ($before + 1), $renewed->json('data.version'));
        $this->assertEquals(43200, $renewed->json('data.ttl'));
        $this->getJson('api/data-version', $this->headers())->assertJsonPath('data.version', $renewed->json('data.version'));

        $this->getJson('api/accounts', $this->headers())->assertHeader('X-Pocket-Cache', 'miss');
        $this->assertEquals(2, $this->calls('GET accounts'));
    }

    public function test_answers_carry_server_timing()
    {
        $miss = $this->getJson('api/accounts', $this->headers());
        $hit = $this->getJson('api/accounts', $this->headers());

        $this->assertMatchesRegularExpression('/^total;dur=[\d.]+, upstream;dur=[\d.]+;desc="1 call", cache;desc=miss$/', $miss->headers->get('Server-Timing'));
        // The token check (about/user) is the one call of a hit, made once and then kept
        $this->assertMatchesRegularExpression('/upstream;dur=[\d.]+;desc="1 call", cache;desc=hit$/', $hit->headers->get('Server-Timing'));
        $third = $this->getJson('api/accounts', $this->headers());
        $this->assertMatchesRegularExpression('/upstream;dur=[\d.]+;desc="0 calls", cache;desc=hit$/', $third->headers->get('Server-Timing'));
    }
}
