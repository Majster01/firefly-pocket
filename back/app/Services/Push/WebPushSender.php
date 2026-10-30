<?php

namespace App\Services\Push;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class WebPushSender implements PushSender
{
    // A notification the device could not receive for a day is no longer news
    const TTL_SECONDS = 86400;

    // Apple's push service only accepts RFC 8291 (the library defaults to the older aesgcm)
    const CONTENT_ENCODING = 'aes128gcm';

    public function send(Collection $subscriptions, string $payload): array
    {
        $webPush = new WebPush(
            ['VAPID' => ['subject' => config('app.vapid_subject'), 'publicKey' => config('app.vapid_public_key'), 'privateKey' => config('app.vapid_private_key')]],
            ['TTL' => self::TTL_SECONDS]
        );

        $results = [];
        foreach ($subscriptions as $subscription) {
            try {
                $webPush->queueNotification(Subscription::create(['endpoint' => $subscription->endpoint, 'keys' => ['p256dh' => $subscription->p256dh, 'auth' => $subscription->auth], 'contentEncoding' => self::CONTENT_ENCODING]), $payload);
            } catch (Throwable $e) {
                // Malformed keys, ...
                Log::warning('Push subscription ' . $subscription->id . ' could not be queued: ' . $e->getMessage());
                $results[$subscription->endpoint] = self::FAILED;
            }
        }

        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                Log::warning('Push to ' . parse_url($report->getEndpoint(), PHP_URL_HOST) . ' failed: ' . $report->getReason());
            }
            $results[$report->getEndpoint()] = $report->isSuccess() ? self::SENT : ($report->isSubscriptionExpired() ? self::GONE : self::FAILED);
        }
        return $results;
    }
}
