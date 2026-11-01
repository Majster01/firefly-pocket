<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps Firefly reads, so opening a page does not ask Firefly again for data that has not changed.
 *
 * Data changes rarely and at known moments: the nightly bank import, approving rule suggestions, edits made in the app.
 * So there is one data version for everyone instead of per-resource invalidation: every cached answer is stored under
 * the version current when it was asked for, and a change starts a new version (bump). Old entries are never read again
 * and expire with the TTL. One version for all tokens: a phone and a desktop with their own tokens see the same Firefly.
 *
 * Entries are per token (its hash), and served only to a token Firefly accepted (getUser(), itself cached for a while).
 */
class ResponseCache
{
    const VERSION_KEY = 'pocket:data-version';
    const KEY_PREFIX = 'pocket:response:';
    const MAX_BODY_BYTES = 4 * 1024 * 1024;

    // Pocket's own data or data that is not Firefly's: never cached here (paths after /api/)
    const NOT_CACHED = [
        'assistant', 'bank-connections', 'currencies/exchange', 'custom-icons', 'data-version', 'info', 'internal', 'profiles', 'push',
        'system-status', 'test', 'transaction-templates', 'user', 'accounts/groups',
    ];

    // Requests other than GET that change nothing in Firefly or in what is cached: no new version
    const NO_CHANGE = [
        'assistant', 'internal', 'profiles', 'push', 'rule-suggestions/preview', 'transaction-templates',
    ];

    public function enabled(): bool
    {
        return (bool) config('app.response_cache');
    }

    public function store(): Repository
    {
        return Cache::store(config('app.response_cache_store'));
    }

    public function version(): int
    {
        $version = $this->store()->get(self::VERSION_KEY);
        if ($version === null) {
            // Not 0: if the store lost the key (restart, eviction), entries of an earlier version must not come back
            $this->store()->add(self::VERSION_KEY, (int) floor(microtime(true) * 1000), null);
            $version = $this->store()->get(self::VERSION_KEY);
        }
        return (int) $version;
    }

    /** Something changed in Firefly: everything cached so far is out of date. */
    public function bump(): void
    {
        if (!$this->enabled()) {
            return;
        }
        $this->version();
        $this->store()->increment(self::VERSION_KEY);
    }

    public function isCacheable(Request $request): bool
    {
        return $request->isMethod('GET') && (bool) $request->bearerToken() && !$this->matches($request, self::NOT_CACHED);
    }

    public function changesData(Request $request): bool
    {
        return !$request->isMethodSafe() && !$this->matches($request, self::NO_CHANGE);
    }

    /** The client asked for a fresh answer (fetch with cache: 'reload' or 'no-store') */
    public function isBypass(Request $request): bool
    {
        $directives = strtolower($request->headers->get('Cache-Control', '') . ',' . $request->headers->get('Pragma', ''));
        return str_contains($directives, 'no-cache') || str_contains($directives, 'no-store');
    }

    public function get(int $version, Request $request): ?array
    {
        $entry = $this->store()->get($this->key($version, $request));
        return is_array($entry) ? $entry : null;
    }

    /** Stores a 200 answer. Returns the entry, or null when it is not kept (an error, a file, too big). */
    public function put(int $version, Request $request, Response $response): ?array
    {
        $body = $response->getContent();
        if ($response->getStatusCode() !== 200 || !is_string($body) || strlen($body) > self::MAX_BODY_BYTES) {
            return null;
        }
        $entry = [
            'body' => $body,
            'type' => $response->headers->get('Content-Type'),
            'etag' => self::etag($body),
        ];
        $this->store()->put($this->key($version, $request), $entry, max(1, (int) config('app.response_cache_ttl')));
        return $entry;
    }

    /** From the body, so it is right whatever the version or token: the same answer is the same tag. */
    public static function etag(string $body): string
    {
        return '"' . hash('xxh128', $body) . '"';
    }

    /**
     * The day is part of the key: some answers move with the date alone (a recurring transaction's next date, a
     * balance with transactions booked ahead), so nothing is kept past midnight.
     */
    private function key(int $version, Request $request): string
    {
        $day = now(config('app.response_cache_timezone'))->toDateString();
        return self::KEY_PREFIX . $version . ':' . $day . ':' . getAuthTokenHash() . ':' . hash('sha256', $request->getRequestUri());
    }

    private function matches(Request $request, array $prefixes): bool
    {
        $path = Str::after($request->path(), 'api/');
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, "$prefix/")) {
                return true;
            }
        }
        return false;
    }
}
