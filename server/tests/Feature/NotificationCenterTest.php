<?php

namespace Tests\Feature;

use App\Models\InboxNotification;
use App\Models\InboxNotificationRead;
use App\Models\PushSubscription;
use App\Services\NotificationCenterService;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakePushSender;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private $notifyToken = 'n0tify-t0ken-n0tify-t0ken-n0tify-t0ken-1234';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.vapid_public_key' => 'public-key',
            'app.vapid_private_key' => 'private-key',
            'app.notify_api_token' => $this->notifyToken,
        ]);
        $this->sender = new FakePushSender();
        $this->app->instance(PushSender::class, $this->sender);

        Http::fake(function ($request) {
            return match ($request->header('Authorization')[0] ?? $request->header('authorization')[0] ?? '') {
                'Bearer test-token' => Http::response(['data' => ['id' => '1']]),
                'Bearer other-token' => Http::response(['data' => ['id' => '2']]),
                default => Http::response(null, 401),
            };
        });
    }

    private FakePushSender $sender;

    private function auth($token = 'test-token')
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    private function notify($body = null)
    {
        return $this->postJson('api/internal/notify', $body ?? ['title' => 'Backup failed', 'body' => 'Check the server'], $this->auth($this->notifyToken));
    }

    private function subscription($endpoint, $userId = '1', $preferences = null)
    {
        return PushSubscription::create(['user_id' => $userId, 'endpoint' => $endpoint, 'p256dh' => 'p', 'auth' => 'a', 'preferences' => $preferences]);
    }

    private function unread($token = 'test-token')
    {
        return $this->getJson('api/notifications/unread-count', $this->auth($token))->json('count');
    }

    private function ids($token = 'test-token', $query = '')
    {
        return collect($this->getJson('api/notifications' . $query, $this->auth($token))->json('data'))->pluck('id')->all();
    }

    // ---- storing ----

    public function test_notify_stores_the_notification_even_without_subscriptions()
    {
        $this->notify(['title' => 'Backup failed', 'body' => 'Check the server', 'path' => '/system-status'])->assertOk();

        $this->assertDatabaseCount('inbox_notifications', 1);
        $this->assertDatabaseHas('inbox_notifications', ['kind' => 'alert', 'title' => 'Backup failed', 'body' => 'Check the server', 'path' => '/system-status', 'quiet' => false]);
    }

    public function test_notify_stores_it_even_when_every_device_skips_it()
    {
        $this->subscription('https://push.example/1', '1', ['import_failure' => false]);

        $this->notify(['title' => 'NLB import failed', 'body' => 'Reconnect', 'event' => 'import.failure'])
            ->assertOk()->assertJsonPath('skipped', 1)->assertJsonPath('sent', 0);

        $this->assertDatabaseHas('inbox_notifications', ['kind' => 'import.failure']);
        $this->assertSame([], $this->sender->payloads);
    }

    public function test_an_invalid_or_unauthorized_notify_stores_nothing()
    {
        $this->notify(['title' => 'T'])->assertUnprocessable();
        $this->postJson('api/internal/notify', ['title' => 'T', 'body' => 'B'], $this->auth('wrong-token-wrong-token-wrong-token-1'))->assertUnauthorized();

        $this->assertDatabaseCount('inbox_notifications', 0);
    }

    public function test_a_center_that_fails_does_not_keep_the_push_from_going_out()
    {
        $this->subscription('https://push.example/1');
        $this->mock(NotificationCenterService::class, fn($mock) => $mock->shouldReceive('store')->andThrow(new \RuntimeException('disk full')));

        $this->notify()->assertOk()->assertJsonPath('sent', 1);

        $this->assertSame([['title' => 'Backup failed', 'body' => 'Check the server', 'path' => '/']], $this->sender->payloads);
    }

    // ---- tags ----

    public function test_a_changed_message_with_the_same_tag_replaces_the_old_one_and_is_unread_again()
    {
        $this->notify(['title' => '4 suggestions waiting', 'body' => 'Review them', 'event' => 'suggestions.digest', 'tag' => 'suggestions']);
        $first = $this->ids()[0];
        $this->postJson("api/notifications/$first/read", [], $this->auth())->assertOk();
        $this->assertSame(0, $this->unread());

        $this->notify(['title' => '5 suggestions waiting', 'body' => 'Review them', 'event' => 'suggestions.digest', 'tag' => 'suggestions']);

        $this->assertDatabaseCount('inbox_notifications', 1);
        $this->assertDatabaseCount('inbox_notification_reads', 0);
        $this->assertSame(1, $this->unread());
        $this->assertGreaterThan($first, $this->ids()[0]);
    }

    public function test_the_same_message_with_the_same_tag_keeps_its_row_and_who_read_it()
    {
        $digest = ['title' => '4 suggestions waiting', 'body' => 'Review them', 'event' => 'suggestions.digest', 'tag' => 'suggestions'];
        $this->notify($digest);
        $id = $this->ids()[0];
        $this->postJson("api/notifications/$id/read", [], $this->auth())->assertOk();

        $this->notify($digest);

        $this->assertSame([$id], $this->ids());
        $this->assertSame(0, $this->unread());
    }

    public function test_a_replaced_notification_comes_first_in_the_list()
    {
        $this->notify(['title' => 'Digest 1', 'body' => 'B', 'tag' => 'suggestions']);
        $this->notify(['title' => 'Other', 'body' => 'B']);
        $this->notify(['title' => 'Digest 2', 'body' => 'B', 'tag' => 'suggestions']);

        $titles = collect($this->getJson('api/notifications', $this->auth())->json('data'))->pluck('title')->all();

        $this->assertSame(['Digest 2', 'Other'], $titles);
    }

    public function test_messages_without_a_tag_or_with_different_tags_are_all_kept()
    {
        $this->notify(['title' => 'A', 'body' => 'B']);
        $this->notify(['title' => 'A', 'body' => 'B']);
        $this->notify(['title' => 'A', 'body' => 'B', 'tag' => 'one']);
        $this->notify(['title' => 'A', 'body' => 'B', 'tag' => 'two']);

        $this->assertDatabaseCount('inbox_notifications', 4);
    }

    // ---- retention ----

    private function insertOld($daysAgo, $count = 1)
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['kind' => 'alert', 'title' => 'Old', 'body' => 'B', 'path' => '/', 'quiet' => false, 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('inbox_notifications')->insert($chunk);
        }
    }

    public function test_notify_drops_what_is_older_than_90_days_and_their_reads()
    {
        $this->insertOld(91);
        $this->insertOld(89);
        $old = InboxNotification::orderBy('id')->first();
        InboxNotificationRead::create(['user_id' => '1', 'notification_id' => $old->id, 'read_at' => now()]);
        Log::spy();

        $this->notify();

        $this->assertSame(2, InboxNotification::count());
        $this->assertDatabaseMissing('inbox_notifications', ['id' => $old->id]);
        $this->assertDatabaseCount('inbox_notification_reads', 0);
        // Counts of rows, named so a reader of the log does not take them for days
        Log::shouldHaveReceived('info')->with('Notifications pruned', ['expired' => 1, 'beyond_newest' => 0, 'retention_days' => 90, 'max_rows' => 500])->once();
    }

    public function test_notify_keeps_only_the_newest_500()
    {
        $this->insertOld(1, 500);
        $oldest = InboxNotification::orderBy('id')->first()->id;

        $this->notify();

        $this->assertSame(500, InboxNotification::count());
        $this->assertDatabaseMissing('inbox_notifications', ['id' => $oldest]);
        $this->assertDatabaseHas('inbox_notifications', ['title' => 'Backup failed']);
    }

    // ---- the badge in the push ----

    public function test_the_push_carries_the_id_of_the_stored_notification_so_a_tap_can_mark_it_read()
    {
        $this->subscription('https://push.example/1');

        $this->notify()->assertOk()->assertJsonPath('sent', 1);

        $this->assertSame($this->ids()[0], $this->sender->payloadFor['https://push.example/1']['id']);
    }

    public function test_each_users_devices_get_that_users_unread_count_as_the_badge()
    {
        $this->notify(['title' => 'First', 'body' => 'B']);
        $first = $this->ids()[0];
        $this->postJson("api/notifications/$first/read", [], $this->auth())->assertOk();
        $this->subscription('https://push.example/u1-phone', '1');
        $this->subscription('https://push.example/u1-laptop', '1');
        $this->subscription('https://push.example/u2-phone', '2');

        $this->notify(['title' => 'Second', 'body' => 'B'])->assertOk()->assertJsonPath('sent', 3);

        $this->assertCount(2, $this->sender->payloads);
        $this->assertSame(1, $this->sender->payloadFor['https://push.example/u1-phone']['badge']);
        $this->assertSame(1, $this->sender->payloadFor['https://push.example/u1-laptop']['badge']);
        $this->assertSame(2, $this->sender->payloadFor['https://push.example/u2-phone']['badge']);
    }

    public function test_a_routine_import_does_not_raise_the_badge()
    {
        $this->subscription('https://push.example/1');

        $this->notify(['title' => 'NLB import succeeded', 'body' => '3 new transactions', 'event' => 'import.success', 'count' => 3])->assertOk();

        $this->assertSame(0, $this->sender->payloadFor['https://push.example/1']['badge']);
        $this->assertSame(0, $this->unread());
        $this->assertSame([true], collect($this->getJson('api/notifications', $this->auth())->json('data'))->pluck('quiet')->all());
    }

    // ---- the API ----

    public function test_the_endpoints_need_a_signed_in_user()
    {
        $this->getJson('api/notifications', $this->auth('bad-token'))->assertUnauthorized();
        $this->getJson('api/notifications/unread-count', $this->auth('bad-token'))->assertUnauthorized();
        $this->postJson('api/notifications/1/read', [], $this->auth('bad-token'))->assertUnauthorized();
        $this->postJson('api/notifications/read-all', [], $this->auth('bad-token'))->assertUnauthorized();
    }

    public function test_the_list_is_newest_first_and_paged_by_id()
    {
        foreach (['A', 'B', 'C'] as $title) {
            $this->notify(['title' => $title, 'body' => 'B']);
        }
        [$c, $b, $a] = $this->ids();

        $page = $this->getJson('api/notifications?limit=2', $this->auth());
        $page->assertOk()->assertJsonPath('next', $b)->assertJsonPath('unread', 3);
        $this->assertSame([$c, $b], collect($page->json('data'))->pluck('id')->all());

        $last = $this->getJson("api/notifications?limit=2&before=$b", $this->auth());
        $this->assertSame([$a], collect($last->json('data'))->pluck('id')->all());
        $last->assertJsonPath('next', null);
    }

    public function test_a_page_that_ends_exactly_at_the_last_message_has_no_next()
    {
        $this->notify(['title' => 'A', 'body' => 'B']);
        $this->notify(['title' => 'B', 'body' => 'B']);

        $this->getJson('api/notifications?limit=2', $this->auth())->assertOk()->assertJsonPath('next', null);
    }

    public function test_the_list_validates_the_paging()
    {
        foreach (['limit=0', 'limit=51', 'limit=many', 'before=0', 'before=x'] as $query) {
            $this->getJson("api/notifications?$query", $this->auth())->assertUnprocessable();
        }
    }

    public function test_the_list_shows_what_this_user_has_read_and_the_unread_total()
    {
        $this->notify(['title' => 'A', 'body' => 'B']);
        $this->notify(['title' => 'B', 'body' => 'B']);
        [$b, $a] = $this->ids();
        $this->postJson("api/notifications/$a/read", [], $this->auth())->assertOk()->assertJsonPath('unread', 1);

        $mine = $this->getJson('api/notifications', $this->auth());
        $mine->assertJsonPath('unread', 1);
        $this->assertSame([false, true], collect($mine->json('data'))->pluck('read')->all());

        $theirs = $this->getJson('api/notifications', $this->auth('other-token'));
        $theirs->assertJsonPath('unread', 2);
        $this->assertSame([false, false], collect($theirs->json('data'))->pluck('read')->all());
    }

    public function test_reading_twice_changes_nothing_and_an_unknown_notification_is_not_found()
    {
        $this->notify();
        $id = $this->ids()[0];

        $this->postJson("api/notifications/$id/read", [], $this->auth())->assertOk();
        $this->postJson("api/notifications/$id/read", [], $this->auth())->assertOk();
        $this->postJson('api/notifications/9999/read', [], $this->auth())->assertNotFound();

        $this->assertDatabaseCount('inbox_notification_reads', 1);
    }

    public function test_read_all_marks_what_is_there_for_this_user_only()
    {
        $this->notify(['title' => 'A', 'body' => 'B']);
        $this->notify(['title' => 'B', 'body' => 'B']);

        $this->postJson('api/notifications/read-all', [], $this->auth())->assertOk()->assertJsonPath('unread', 0);
        $this->notify(['title' => 'C', 'body' => 'B']);

        $this->assertSame(1, $this->unread());
        $this->assertSame(3, $this->unread('other-token'));
    }

    public function test_read_all_up_to_leaves_a_newer_notification_unread()
    {
        $this->notify(['title' => 'A', 'body' => 'B']);
        $shown = $this->ids()[0];
        $this->notify(['title' => 'B', 'body' => 'B']);

        $this->postJson('api/notifications/read-all', ['up_to' => $shown], $this->auth())->assertOk()->assertJsonPath('unread', 1);
        $this->postJson('api/notifications/read-all', ['up_to' => 'x'], $this->auth())->assertUnprocessable();
    }

    public function test_read_all_twice_leaves_one_read_per_notification()
    {
        $this->notify(['title' => 'A', 'body' => 'B']);
        $this->notify(['title' => 'B', 'body' => 'B']);
        $this->postJson("api/notifications/{$this->ids()[1]}/read", [], $this->auth())->assertOk();

        $this->postJson('api/notifications/read-all', [], $this->auth())->assertOk()->assertJsonPath('unread', 0);
        $this->postJson('api/notifications/read-all', [], $this->auth())->assertOk()->assertJsonPath('unread', 0);

        $this->assertDatabaseCount('inbox_notification_reads', 2);
    }

    public function test_read_all_up_to_zero_marks_nothing_and_a_negative_one_is_refused()
    {
        $this->notify();

        $this->postJson('api/notifications/read-all', ['up_to' => 0], $this->auth())->assertOk()->assertJsonPath('unread', 1);
        $this->postJson('api/notifications/read-all', ['up_to' => -1], $this->auth())->assertUnprocessable();
    }

    public function test_reading_a_routine_import_does_not_lower_the_unread_count()
    {
        $this->notify(['title' => 'Backup failed', 'body' => 'B']);
        $this->notify(['title' => 'NLB import succeeded', 'body' => '3 new', 'event' => 'import.success', 'count' => 3]);
        $import = $this->ids()[0];

        $this->postJson("api/notifications/$import/read", [], $this->auth())->assertOk()->assertJsonPath('unread', 1);

        $this->assertSame(1, $this->unread());
    }

    // ---- the list's shape ----

    public function test_a_list_item_has_the_shape_the_app_reads()
    {
        $this->notify(['title' => 'NLB import failed', 'body' => 'Reconnect', 'path' => '/bank-connections', 'event' => 'import.failure', 'count' => 2]);
        $id = $this->ids()[0];

        $response = $this->getJson('api/notifications', $this->auth())->assertOk();

        $this->assertSame(
            ['id' => $id, 'kind' => 'import.failure', 'title' => 'NLB import failed', 'body' => 'Reconnect', 'path' => '/bank-connections', 'count' => 2, 'quiet' => false, 'read' => false],
            collect($response->json('data.0'))->except('created_at')->all(),
        );
        // groupByDay reads the zone from the Z
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/', $response->json('data.0.created_at'));
    }

    public function test_the_page_size_defaults_to_20_and_can_be_50()
    {
        $this->insertOld(1, 21);

        $page = $this->getJson('api/notifications', $this->auth())->assertOk();
        $this->assertCount(20, $page->json('data'));
        $this->assertNotNull($page->json('next'));

        $this->getJson('api/notifications?limit=50', $this->auth())->assertOk();
    }

    // ---- retention, the edges ----

    public function test_a_notification_of_exactly_90_days_is_kept_and_a_second_older_is_not()
    {
        $this->freezeTime();
        foreach ([['Exactly', now()->subDays(90)], ['A second older', now()->subDays(90)->subSecond()]] as [$title, $when]) {
            DB::table('inbox_notifications')->insert(['kind' => 'alert', 'title' => $title, 'body' => 'B', 'path' => '/', 'quiet' => false, 'created_at' => $when, 'updated_at' => $when]);
        }

        $this->notify();

        $this->assertDatabaseHas('inbox_notifications', ['title' => 'Exactly']);
        $this->assertDatabaseMissing('inbox_notifications', ['title' => 'A second older']);
    }

    public function test_499_notifications_and_a_new_one_are_all_kept()
    {
        $this->insertOld(1, 499);
        $oldest = InboxNotification::orderBy('id')->first()->id;

        $this->notify();

        $this->assertSame(500, InboxNotification::count());
        $this->assertDatabaseHas('inbox_notifications', ['id' => $oldest]);
    }

    public function test_an_unchanged_tagged_notification_is_not_pruned_for_its_age()
    {
        // Daily: the default (weekly, Monday) would make the push, and this test, depend on the day it runs
        $this->subscription('https://push.example/1', '1', ['suggestions' => ['frequency' => 'daily']]);
        $old = now()->subDays(91);
        DB::table('inbox_notifications')->insert(['kind' => 'suggestions.digest', 'title' => '4 waiting', 'body' => 'B', 'path' => '/', 'tag' => 'suggestions', 'quiet' => false, 'created_at' => $old, 'updated_at' => $old]);

        $this->notify(['title' => '4 waiting', 'body' => 'B', 'event' => 'suggestions.digest', 'tag' => 'suggestions'])->assertOk();

        $this->assertDatabaseCount('inbox_notifications', 1);
        $this->assertSame($this->ids()[0], $this->sender->payloadFor['https://push.example/1']['id']);
    }

    public function test_an_unchanged_tagged_notification_is_not_pruned_for_being_beyond_the_newest_500()
    {
        $this->subscription('https://push.example/1', '1', ['suggestions' => ['frequency' => 'daily']]);
        DB::table('inbox_notifications')->insert(['kind' => 'suggestions.digest', 'title' => '4 waiting', 'body' => 'B', 'path' => '/', 'tag' => 'suggestions', 'quiet' => false, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $this->insertOld(1, 500);

        $this->notify(['title' => '4 waiting', 'body' => 'B', 'event' => 'suggestions.digest', 'tag' => 'suggestions'])->assertOk();

        $id = InboxNotification::where('tag', 'suggestions')->value('id');
        $this->assertNotNull($id);
        $this->assertSame($id, $this->sender->payloadFor['https://push.example/1']['id']);
    }

    public function test_a_long_path_is_stored_too()
    {
        $path = '/bank-connections?' . str_repeat('a', 300);

        $this->notify(['title' => 'T', 'body' => 'B', 'path' => $path])->assertOk();

        $this->assertDatabaseHas('inbox_notifications', ['title' => 'T', 'path' => $path]);
        // SQLite does not enforce varchar lengths, so this is what guards Postgres, where a string() column would refuse the path
        $column = collect(Schema::getColumns('inbox_notifications'))->firstWhere('name', 'path');
        $this->assertSame('text', $column['type_name']);
    }

    // ---- when the center misbehaves ----

    private function collideOnTag(bool $always)
    {
        $collisions = 0;
        InboxNotification::creating(function ($notification) use (&$collisions, $always) {
            if ($collisions++ === 0 || $always) {
                DB::table('inbox_notifications')->insert(['kind' => 'alert', 'title' => 'Rival', 'body' => 'B', 'path' => '/', 'tag' => $notification->tag, 'quiet' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function test_two_messages_with_one_tag_at_once_are_settled_by_one_retry()
    {
        Log::spy();
        $this->collideOnTag(always: false);

        $this->notify(['title' => 'Digest', 'body' => 'B', 'tag' => 'suggestions'])->assertOk();

        Log::shouldHaveReceived('warning')->with('Notification tag collided, trying again', ['tag' => 'suggestions'])->once();
        $this->assertSame(['Digest'], InboxNotification::pluck('title')->all());
    }

    public function test_a_collision_that_repeats_is_given_up_and_the_push_still_goes_out_without_id_or_badge()
    {
        Log::spy();
        $this->subscription('https://push.example/1');
        $this->collideOnTag(always: true);

        $this->notify(['title' => 'Digest', 'body' => 'B', 'tag' => 'suggestions'])->assertOk()->assertJsonPath('sent', 1);

        Log::shouldHaveReceived('error')->with('Notification could not be stored', \Mockery::type('array'))->once();
        $this->assertSame(['title' => 'Digest', 'body' => 'B', 'path' => '/', 'tag' => 'suggestions'], $this->sender->payloadFor['https://push.example/1']);
    }

    public function test_unread_counts_that_cannot_be_read_do_not_keep_the_push_from_going_out()
    {
        $this->subscription('https://push.example/1');
        $this->partialMock(NotificationCenterService::class, fn($mock) => $mock->shouldReceive('unreadCounts')->andThrow(new \RuntimeException('db gone')));

        $this->notify()->assertOk()->assertJsonPath('sent', 1);

        $this->assertArrayNotHasKey('badge', $this->sender->payloadFor['https://push.example/1']);
        $this->assertArrayHasKey('id', $this->sender->payloadFor['https://push.example/1']);
    }
}
