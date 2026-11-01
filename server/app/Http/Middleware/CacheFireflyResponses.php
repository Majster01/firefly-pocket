<?php

namespace App\Http\Middleware;

use App\Services\ResponseCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers Firefly reads from ResponseCache, and starts a new data version after a change made through Pocket.
 *
 * Every cacheable answer carries an ETag (from its body) with Cache-Control: private, no-cache, so the browser asks
 * again each time but gets an empty 304 when nothing changed. X-Pocket-Cache says hit, miss or bypass.
 */
class CacheFireflyResponses
{
    public function __construct(private ResponseCache $cache)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->cache->enabled()) {
            return $next($request);
        }
        if ($this->cache->isCacheable($request)) {
            return $this->read($request, $next);
        }

        $response = $next($request);
        // Only a token Firefly accepts can start a new version: anyone else could keep the cache empty
        if ($this->cache->changesData($request) && $request->bearerToken() && getUser()) {
            $this->cache->bump();
        }
        return $response;
    }

    private function read(Request $request, Closure $next): Response
    {
        // Read before asking Firefly: a change made meanwhile starts a newer version, and this answer stays behind
        $version = $this->cache->version();
        $bypass = $this->cache->isBypass($request);

        if (!$bypass && ($entry = $this->cache->get($version, $request)) && getUser()) {
            return $this->answer($request, response($entry['body'], 200, array_filter(['Content-Type' => $entry['type']])), $entry['etag'], 'hit');
        }

        $response = $next($request);
        $entry = $this->cache->put($version, $request, $response);
        if (!$entry) {
            return $response;
        }
        return $this->answer($request, $response, $entry['etag'], $bypass ? 'bypass' : 'miss');
    }

    private function answer(Request $request, Response $response, string $etag, string $status): Response
    {
        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->headers->set('X-Pocket-Cache', $status);
        if (in_array($etag, $request->getETags(), true)) {
            $response->setNotModified();
        }
        return $response;
    }
}
