<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Services\Push\PushPreferences;
use App\Services\Push\PushSender;

/**
 * Web Push: stores the devices that opted in and sends them notifications (and the app badge count).
 * The notifications come from the FireflyX importer/suggester through POST /api/internal/notify.
 */
class PushNotificationService
{
    public function __construct(private PushSender $sender)
    {
    }

    public function isConfigured(): bool
    {
        return !empty(config('app.vapid_public_key')) && !empty(config('app.vapid_private_key'));
    }

    public function getPublicKey()
    {
        return $this->isConfigured() ? config('app.vapid_public_key') : null;
    }

    public function subscribe($userId, $endpoint, $p256dh, $auth, $userAgent)
    {
        // An endpoint is one browser installation: it belongs to whoever signed in there last
        return PushSubscription::updateOrCreate(
            ['endpoint' => $endpoint],
            ['user_id' => $userId, 'p256dh' => $p256dh, 'auth' => $auth, 'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null]
        );
    }

    public function unsubscribe($endpoint)
    {
        PushSubscription::allowed()->where('endpoint', $endpoint)->delete();
    }

    public function preferences($endpoint): ?array
    {
        $subscription = PushSubscription::allowed()->where('endpoint', $endpoint)->first();
        return $subscription ? PushPreferences::for($subscription) : null;
    }

    public function savePreferences($endpoint, array $input): ?array
    {
        $subscription = PushSubscription::allowed()->where('endpoint', $endpoint)->first();
        if (!$subscription) {
            return null;
        }
        $subscription->update(['preferences' => PushPreferences::merge($input)]);
        return PushPreferences::for($subscription);
    }

    /**
     * Sends to the stored subscriptions that want this kind of message. $message: title, body, path, and optionally
     * badge, tag, and the kind of message (event, with count for the number of new transactions), which only decides who
     * gets it and is not part of what the device receives.
     * Subscriptions the push service reports gone are deleted.
     */
    public function notify(array $message): array
    {
        $event = $message['event'] ?? null;
        $count = isset($message['count']) ? (int) $message['count'] : null;
        unset($message['event'], $message['count']);

        $now = now(config('app.notify_timezone'));
        $all = PushSubscription::all();
        $subscriptions = $all->filter(fn($subscription) => PushPreferences::wants($subscription, $event, $count, $now))->values();
        $skipped = $all->count() - $subscriptions->count();
        if ($subscriptions->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => $skipped];
        }

        $results = collect($this->sender->send($subscriptions, json_encode($message)));
        $gone = $results->filter(fn($result) => $result === PushSender::GONE)->keys();
        PushSubscription::whereIn('endpoint', $gone)->delete();

        return [
            'sent' => $results->filter(fn($result) => $result === PushSender::SENT)->count(),
            'failed' => $subscriptions->count() - $results->filter(fn($result) => $result === PushSender::SENT)->count() - $gone->count(),
            'removed' => $gone->count(),
            'skipped' => $skipped,
        ];
    }
}
