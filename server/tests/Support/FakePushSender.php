<?php

namespace Tests\Support;

use App\Services\Push\PushSender;
use Illuminate\Support\Collection;

/**
 * Records what would be pushed. $outcomes maps an endpoint to SENT, FAILED or GONE (default SENT);
 * 'throw' makes the delivery to that endpoint fail like a network error would.
 */
class FakePushSender implements PushSender
{
    public array $payloads = [];
    public array $endpoints = [];

    public function __construct(private array $outcomes = [])
    {
    }

    public function send(Collection $subscriptions, string $payload): array
    {
        $this->payloads[] = json_decode($payload, true);
        $results = [];
        foreach ($subscriptions as $subscription) {
            $this->endpoints[] = $subscription->endpoint;
            $results[$subscription->endpoint] = $this->outcomes[$subscription->endpoint] ?? self::SENT;
        }
        return $results;
    }
}
