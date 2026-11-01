<?php

namespace App\Services;

use App\Jobs\WarmUpResponseCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Fetches again what Home asked for, shortly after the data version moved on (the nightly import, a webhook, a save),
 * so the next time Home opens its answers are kept already.
 *
 * Pocket does not store anyone's token. The warm-up asks Firefly with its own token (WARMUP_FIREFLY_TOKEN, a personal
 * access token of the same Firefly user) and keeps the answers for each token that recently opened Home, after
 * checking that both belong to the same Firefly user.
 *
 * Needs a queue worker (QUEUE_CONNECTION=redis and Horizon in the Docker image); with the sync queue it is off, as it
 * would hold up the request that changed the data.
 */
class CacheWarmUp
{
    const READS_PREFIX = 'pocket:warm:reads:';
    const OWNERS_KEY = 'pocket:warm:owners';
    const PENDING_KEY = 'pocket:warm:pending';
    // Reads of Home remembered for this long, per token
    const REMEMBER_SECONDS = 8 * 24 * 3600;
    const MAX_PATHS = 60;
    // Not batched by the web app (slow), but on Home all the same: the suggestions badge
    const ALWAYS = ['rule-suggestions'];

    public function __construct(private ResponseCache $cache)
    {
    }

    public function isOn(): bool
    {
        return $this->cache->enabled() && config('app.warmup_firefly_token') && config('queue.default') !== 'sync';
    }

    /** Remembers what Home of this token read, and for which Firefly user. */
    public function remember(Request $request, array $paths): void
    {
        $userId = data_get(getUser(), 'data.id');
        if (!$this->isOn() || !$userId || !$paths) {
            return;
        }
        $owner = hash('sha256', (string) $request->bearerToken());
        $store = $this->cache->store();
        $now = time();

        $reads = $store->get(self::READS_PREFIX . $owner);
        $seen = is_array($reads) && ($reads['user'] ?? null) === $userId ? $reads['paths'] : [];
        foreach ($paths as $path) {
            $seen[$path] = $now;
        }
        $seen = array_filter($seen, fn($at) => $at > $now - self::REMEMBER_SECONDS);
        arsort($seen);
        $store->put(self::READS_PREFIX . $owner, ['user' => $userId, 'paths' => array_slice($seen, 0, self::MAX_PATHS, true)], self::REMEMBER_SECONDS);

        $owners = $store->get(self::OWNERS_KEY);
        $owners = array_filter(is_array($owners) ? $owners : [], fn($at) => $at > $now - self::REMEMBER_SECONDS);
        $owners[$owner] = $now;
        $store->put(self::OWNERS_KEY, $owners, self::REMEMBER_SECONDS);
    }

    /** Called on every new data version: one warm-up a little later, however many changes come in meanwhile. */
    public function schedule(): void
    {
        if (!$this->isOn()) {
            return;
        }
        $delay = max(0, (int) config('app.warmup_delay'));
        if ($this->cache->store()->add(self::PENDING_KEY, true, $delay + 300)) {
            WarmUpResponseCache::dispatch()->delay(now()->addSeconds($delay));
        }
    }

    /** @return array<string, array{user: string, paths: string[]}> by token hash */
    public function remembered(): array
    {
        $store = $this->cache->store();
        $owners = $store->get(self::OWNERS_KEY);
        $result = [];
        foreach (array_keys(is_array($owners) ? $owners : []) as $owner) {
            $reads = $store->get(self::READS_PREFIX . $owner);
            if (is_array($reads) && isset($reads['user'], $reads['paths'])) {
                $result[$owner] = ['user' => (string) $reads['user'], 'paths' => [...array_keys($reads['paths']), ...self::ALWAYS]];
            }
        }
        return $result;
    }

    /** The job: answers kept for every token that opened Home lately. Returns how many reads were warmed. */
    public function run(BatchReader $reader): int
    {
        $this->cache->store()->forget(self::PENDING_KEY);
        if (!$this->cache->enabled() || !config('app.warmup_firefly_token')) {
            return 0;
        }
        $request = Request::create('/api/batch', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . config('app.warmup_firefly_token'),
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $previous = app('request');
        app()->instance('request', $request);
        try {
            $userId = data_get(getUser(), 'data.id');
            if (!$userId) {
                Log::warning('Cache warm-up: Firefly refused WARMUP_FIREFLY_TOKEN');
                return 0;
            }
            $count = 0;
            foreach ($this->remembered() as $owner => $reads) {
                // Only for tokens of the same Firefly user: never someone else's data under another token
                if ($reads['user'] !== (string) $userId) {
                    continue;
                }
                $answers = $reader->read($request, $reads['paths'], $owner);
                $count += count(array_filter($answers, fn($answer) => $answer['status'] === 200));
            }
            return $count;
        } finally {
            app()->instance('request', $previous);
        }
    }
}
