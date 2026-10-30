<?php

namespace App\Services;

use App\Models\PushSubscription;
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

    /**
     * Sends to every stored subscription. $message: title, body, path, and optionally badge and tag.
     * Subscriptions the push service reports gone are deleted.
     */
    public function notify(array $message): array
    {
        $subscriptions = PushSubscription::all();
        if ($subscriptions->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'removed' => 0];
        }

        $results = collect($this->sender->send($subscriptions, json_encode($message)));
        $gone = $results->filter(fn($result) => $result === PushSender::GONE)->keys();
        PushSubscription::whereIn('endpoint', $gone)->delete();

        return [
            'sent' => $results->filter(fn($result) => $result === PushSender::SENT)->count(),
            'failed' => $subscriptions->count() - $results->filter(fn($result) => $result === PushSender::SENT)->count() - $gone->count(),
            'removed' => $gone->count(),
        ];
    }
}
