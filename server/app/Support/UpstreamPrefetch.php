<?php

namespace App\Support;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;

/**
 * Firefly answers fetched ahead, all at once, for a batch of reads (BatchReader). Controllers then run as usual, and
 * their GET to the same URL with the same token is answered from here (the Http middleware in AppServiceProvider)
 * instead of going to Firefly one after another.
 */
class UpstreamPrefetch
{
    private array $answers = [];

    public function put(string $authorization, string $url, int $status, array $headers, string $body): void
    {
        $this->answers[self::key($authorization, $url)] = [$status, $headers, $body];
    }

    public function take(string $authorization, string $url): ?ResponseInterface
    {
        $answer = $this->answers[self::key($authorization, $url)] ?? null;
        // A new response each time: a body stream is read once
        return $answer ? new Response(...$answer) : null;
    }

    public function clear(): void
    {
        $this->answers = [];
    }

    private static function key(string $authorization, string $url): string
    {
        return $authorization . ' ' . (string) new Uri($url);
    }
}
