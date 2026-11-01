<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePushSender;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private $token = 'test-token';
    private $notifyToken = 'n0tify-t0ken-n0tify-t0ken-n0tify-t0ken-1234';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.vapid_public_key' => 'public-key',
            'app.vapid_private_key' => 'private-key',
            'app.notify_api_token' => $this->notifyToken,
        ]);
        $this->fakePushSender();

        Http::fake(function ($request) {
            return match ($request->header('Authorization')[0] ?? $request->header('authorization')[0] ?? '') {
                'Bearer test-token' => Http::response(['data' => ['id' => '1']]),
                'Bearer other-token' => Http::response(['data' => ['id' => '2']]),
                default => Http::response(null, 401),
            };
        });
    }

    private function fakePushSender($outcomes = [])
    {
        $sender = new FakePushSender($outcomes);
        $this->app->instance(PushSender::class, $sender);
        return $sender;
    }

    private function headers($token = null)
    {
        return ['Authorization' => 'Bearer ' . ($token ?? $this->token)];
    }

    private function notify($body = null, $token = null)
    {
        return $this->postJson('api/internal/notify', $body ?? ['title' => 'Imported', 'body' => '12 new transactions'], $this->headers($token ?? $this->notifyToken));
    }

    private function subscription($endpoint, $userId = '1')
    {
        return PushSubscription::create(['user_id' => $userId, 'endpoint' => $endpoint, 'p256dh' => 'p256dh-' . $endpoint, 'auth' => 'auth-' . $endpoint]);
    }

    // ---- public key ----

    public function test_public_key_is_served_to_signed_in_users()
    {
        $this->getJson('api/push/public-key', $this->headers())
            ->assertOk()
            ->assertExactJson(['data' => ['configured' => true, 'public_key' => 'public-key']]);
    }

    public function test_public_key_says_push_is_not_configured_without_vapid_keys()
    {
        config(['app.vapid_private_key' => '']);

        $this->getJson('api/push/public-key', $this->headers())
            ->assertOk()
            ->assertExactJson(['data' => ['configured' => false, 'public_key' => null]]);
    }

    public function test_public_key_needs_a_signed_in_user()
    {
        $this->getJson('api/push/public-key', $this->headers('bad-token'))->assertUnauthorized();
    }

    // ---- subscriptions ----

    public function test_subscribe_stores_the_subscription_once_per_endpoint()
    {
        $body = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'key-1', 'auth' => 'auth-1']];
        $this->postJson('api/push/subscriptions', $body, $this->headers() + ['User-Agent' => 'TestBrowser/1.0'])->assertOk();

        $body['keys']['auth'] = 'auth-2';
        $this->postJson('api/push/subscriptions', $body, $this->headers())->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => '1', 'endpoint' => 'https://push.example/abc', 'p256dh' => 'key-1', 'auth' => 'auth-2']);
    }

    public function test_subscribe_validates_the_body()
    {
        $this->postJson('api/push/subscriptions', ['endpoint' => 'http://push.example/abc', 'keys' => ['p256dh' => 'k', 'auth' => 'a']], $this->headers())->assertUnprocessable();
        $this->postJson('api/push/subscriptions', ['endpoint' => 'https://push.example/abc'], $this->headers())->assertUnprocessable();
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_subscribe_needs_a_signed_in_user()
    {
        $this->postJson('api/push/subscriptions', ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'k', 'auth' => 'a']], $this->headers('bad-token'))->assertUnauthorized();
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_unsubscribe_deletes_only_the_own_subscription()
    {
        $this->subscription('https://push.example/mine');
        $this->subscription('https://push.example/other', '2');

        $this->deleteJson('api/push/subscriptions', ['endpoint' => 'https://push.example/other'], $this->headers())->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 2);

        $this->deleteJson('api/push/subscriptions', ['endpoint' => 'https://push.example/mine'], $this->headers())->assertOk();
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://push.example/mine']);
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    // ---- notify: token ----

    public function test_notify_needs_the_token()
    {
        $this->postJson('api/internal/notify', ['title' => 'T', 'body' => 'B'])->assertUnauthorized();
        $this->notify(null, 'wrong-token-wrong-token-wrong-token-1')->assertUnauthorized();
        $this->notify(null, 'short')->assertUnauthorized();
    }

    public function test_notify_does_not_accept_a_user_login()
    {
        $this->notify(null, $this->token)->assertUnauthorized();
    }

    public function test_notify_is_unavailable_when_the_token_is_not_set_or_too_short()
    {
        foreach (['', 'too-short-token'] as $configured) {
            config(['app.notify_api_token' => $configured]);
            $this->notify(null, $configured)->assertStatus(503);
            $this->postJson('api/internal/notify', ['title' => 'T', 'body' => 'B'])->assertStatus(503);
        }
    }

    public function test_notify_is_unavailable_without_vapid_keys()
    {
        config(['app.vapid_public_key' => '']);

        $this->notify()->assertStatus(503);
    }

    // ---- notify: validation ----

    public function test_notify_validates_the_body()
    {
        $invalid = [
            [],
            ['title' => 'T'],
            ['body' => 'B'],
            ['title' => str_repeat('a', 101), 'body' => 'B'],
            ['title' => 'T', 'body' => str_repeat('a', 301)],
            ['title' => 'T', 'body' => 'B', 'path' => 'transactions'],
            ['title' => 'T', 'body' => 'B', 'path' => '//evil.example'],
            ['title' => 'T', 'body' => 'B', 'badge' => -1],
            ['title' => 'T', 'body' => 'B', 'badge' => 'many'],
            ['title' => 'T', 'body' => 'B', 'tag' => str_repeat('a', 65)],
            ['title' => 'T', 'body' => 'B', 'event' => 'import.exploded'],
            ['title' => 'T', 'body' => 'B', 'event' => 'import.success', 'count' => -1],
        ];
        foreach ($invalid as $body) {
            $this->notify($body)->assertUnprocessable();
        }
    }

    // ---- notify: fan-out ----

    public function test_notify_without_subscriptions_sends_nothing()
    {
        $sender = $this->fakePushSender();

        $this->notify()->assertOk()->assertExactJson(['sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0]);
        $this->assertSame([], $sender->payloads);
    }

    public function test_notify_sends_to_all_subscriptions_with_the_defaults()
    {
        $sender = $this->fakePushSender();
        $this->subscription('https://push.example/1');
        $this->subscription('https://push.example/2', '2');

        $this->notify()->assertOk()->assertExactJson(['sent' => 2, 'failed' => 0, 'removed' => 0, 'skipped' => 0]);

        $this->assertEqualsCanonicalizing(['https://push.example/1', 'https://push.example/2'], $sender->endpoints);
        $this->assertSame([['title' => 'Imported', 'body' => '12 new transactions', 'path' => '/']], $sender->payloads);
    }

    public function test_notify_passes_the_path_badge_and_tag()
    {
        $sender = $this->fakePushSender();
        $this->subscription('https://push.example/1');

        $this->notify(['title' => 'T', 'body' => 'B', 'path' => '/bank-connections/list', 'badge' => 3, 'tag' => 'import'])->assertOk();
        $this->notify(['title' => 'T', 'body' => 'B', 'badge' => 0])->assertOk();

        $this->assertSame(['title' => 'T', 'body' => 'B', 'path' => '/bank-connections/list', 'badge' => 3, 'tag' => 'import'], $sender->payloads[0]);
        $this->assertSame(['title' => 'T', 'body' => 'B', 'path' => '/', 'badge' => 0], $sender->payloads[1]);
    }

    public function test_notify_removes_gone_subscriptions_and_still_reaches_the_others()
    {
        $sender = $this->fakePushSender([
            'https://push.example/gone' => PushSender::GONE,
            'https://push.example/broken' => PushSender::FAILED,
        ]);
        foreach (['ok-1', 'gone', 'broken', 'ok-2'] as $name) {
            $this->subscription('https://push.example/' . $name);
        }

        $this->notify()->assertOk()->assertExactJson(['sent' => 2, 'failed' => 1, 'removed' => 1, 'skipped' => 0]);

        $this->assertCount(4, $sender->endpoints);
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://push.example/gone']);
        $this->assertDatabaseCount('push_subscriptions', 3);
    }

    // ---- preferences ----

    private function subscriptionWith($endpoint, array $preferences, $userId = '1')
    {
        return PushSubscription::create(['user_id' => $userId, 'endpoint' => $endpoint, 'p256dh' => 'p', 'auth' => 'a', 'preferences' => $preferences]);
    }

    public function test_preferences_default_to_everything_on_weekly_on_monday()
    {
        $this->subscription('https://push.example/1');

        $this->getJson('api/push/preferences?endpoint=' . urlencode('https://push.example/1'), $this->headers())
            ->assertOk()
            ->assertExactJson(['data' => [
                'import_success' => true,
                'import_success_only_new' => true,
                'import_failure' => true,
                'upgrade_success' => true,
                'suggestions' => ['frequency' => 'weekly', 'weekday' => 1],
            ]]);
    }

    public function test_preferences_are_saved_per_device_and_merged_over_the_defaults()
    {
        $this->subscription('https://push.example/1');
        $this->subscription('https://push.example/2');

        $this->putJson('api/push/preferences', [
            'endpoint' => 'https://push.example/1',
            'preferences' => ['import_success_only_new' => false, 'suggestions' => ['frequency' => 'daily']],
        ], $this->headers())->assertOk()->assertJsonPath('data.suggestions', ['frequency' => 'daily', 'weekday' => 1]);

        $this->getJson('api/push/preferences?endpoint=' . urlencode('https://push.example/1'), $this->headers())
            ->assertJsonPath('data.import_success_only_new', false)
            ->assertJsonPath('data.import_failure', true);
        $this->getJson('api/push/preferences?endpoint=' . urlencode('https://push.example/2'), $this->headers())
            ->assertJsonPath('data.import_success_only_new', true);
    }

    public function test_preferences_validate_the_body_and_need_the_own_subscription()
    {
        $this->subscription('https://push.example/mine');
        $this->subscription('https://push.example/other', '2');

        $this->putJson('api/push/preferences', ['endpoint' => 'https://push.example/mine', 'preferences' => ['suggestions' => ['frequency' => 'hourly']]], $this->headers())->assertUnprocessable();
        $this->putJson('api/push/preferences', ['endpoint' => 'https://push.example/mine', 'preferences' => ['suggestions' => ['weekday' => 8]]], $this->headers())->assertUnprocessable();
        $this->putJson('api/push/preferences', ['endpoint' => 'https://push.example/other', 'preferences' => ['import_failure' => false]], $this->headers())->assertNotFound();
        $this->getJson('api/push/preferences?endpoint=' . urlencode('https://push.example/unknown'), $this->headers())->assertNotFound();
    }

    public function test_preferences_need_a_signed_in_user()
    {
        $this->getJson('api/push/preferences?endpoint=x', $this->headers('bad-token'))->assertUnauthorized();
    }

    public function test_an_alert_without_an_event_goes_to_every_device_whatever_they_chose()
    {
        $sender = $this->fakePushSender();
        $this->subscriptionWith('https://push.example/1', ['import_failure' => false, 'import_success' => false, 'upgrade_success' => false, 'suggestions' => ['frequency' => 'off']]);

        $this->notify()->assertOk()->assertExactJson(['sent' => 1, 'failed' => 0, 'removed' => 0, 'skipped' => 0]);
    }

    public function test_events_reach_only_the_devices_that_want_them_and_are_not_part_of_the_payload()
    {
        $sender = $this->fakePushSender();
        $this->subscriptionWith('https://push.example/wants', ['upgrade_success' => true]);
        $this->subscriptionWith('https://push.example/declines', ['upgrade_success' => false]);

        $this->notify(['title' => 'Upgrade successful', 'body' => 'Firefly III 6.6.6 → 6.7.0', 'event' => 'upgrade.success'])
            ->assertOk()->assertExactJson(['sent' => 1, 'failed' => 0, 'removed' => 0, 'skipped' => 1]);

        $this->assertSame(['https://push.example/wants'], $sender->endpoints);
        $this->assertSame([['title' => 'Upgrade successful', 'body' => 'Firefly III 6.6.6 → 6.7.0', 'path' => '/']], $sender->payloads);
    }

    public function test_an_import_without_new_transactions_is_only_sent_when_asked_for()
    {
        $sender = $this->fakePushSender();
        $this->subscriptionWith('https://push.example/quiet', ['import_success_only_new' => true]);
        $this->subscriptionWith('https://push.example/every-night', ['import_success_only_new' => false]);

        $this->notify(['title' => 'NLB import succeeded', 'body' => 'No new transactions', 'event' => 'import.success', 'count' => 0])
            ->assertOk()->assertExactJson(['sent' => 1, 'failed' => 0, 'removed' => 0, 'skipped' => 1]);
        $this->assertSame(['https://push.example/every-night'], $sender->endpoints);

        $this->notify(['title' => 'NLB import succeeded', 'body' => '3 new transactions', 'event' => 'import.success', 'count' => 3])
            ->assertOk()->assertExactJson(['sent' => 2, 'failed' => 0, 'removed' => 0, 'skipped' => 0]);
    }

    public function test_the_suggestions_digest_follows_each_devices_frequency_and_weekday()
    {
        config(['app.notify_timezone' => 'Europe/Ljubljana']);
        $sender = $this->fakePushSender();
        $this->subscriptionWith('https://push.example/daily', ['suggestions' => ['frequency' => 'daily']]);
        $this->subscriptionWith('https://push.example/monday', ['suggestions' => ['frequency' => 'weekly', 'weekday' => 1]]);
        $this->subscriptionWith('https://push.example/tuesday', ['suggestions' => ['frequency' => 'weekly', 'weekday' => 2]]);
        $this->subscriptionWith('https://push.example/off', ['suggestions' => ['frequency' => 'off']]);
        $digest = ['title' => 'Suggestions', 'body' => '4 waiting', 'event' => 'suggestions.digest', 'badge' => 4];

        // 2026-11-02 is a Monday; 21:30 UTC is already Tuesday in Ljubljana
        $this->travelTo(\Carbon\Carbon::parse('2026-11-02 10:00:00', 'UTC'));
        $this->notify($digest)->assertOk()->assertJsonPath('sent', 2)->assertJsonPath('skipped', 2);
        $this->assertEqualsCanonicalizing(['https://push.example/daily', 'https://push.example/monday'], $sender->endpoints);

        $sender->endpoints = [];
        $this->travelTo(\Carbon\Carbon::parse('2026-11-02 23:30:00', 'UTC'));
        $this->notify($digest)->assertOk()->assertJsonPath('sent', 2);
        $this->assertEqualsCanonicalizing(['https://push.example/daily', 'https://push.example/tuesday'], $sender->endpoints);
    }
}
