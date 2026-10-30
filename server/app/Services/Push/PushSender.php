<?php

namespace App\Services\Push;

use Illuminate\Support\Collection;

/**
 * Delivers one payload to push subscriptions. Behind an interface so tests can swap the push services for a fake.
 */
interface PushSender
{
    const SENT = 'sent';
    const FAILED = 'failed';
    // The push service says the subscription does not exist anymore (404/410)
    const GONE = 'gone';

    /**
     * @param Collection $subscriptions PushSubscription models
     * @return array<string, string> SENT, FAILED or GONE per subscription endpoint. One failing subscription never stops the others.
     */
    public function send(Collection $subscriptions, string $payload): array;
}
