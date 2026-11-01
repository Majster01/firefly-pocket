<?php

namespace App\Services;

use App\Http\Controllers\Base\BaseControllerFirefly;
use App\Http\Controllers\FireflyProxyController;
use App\Support\UpstreamPrefetch;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Answers several Firefly reads at once (POST /api/batch, and CacheWarmUp).
 *
 * 1. Reads that are kept are answered from the response cache.
 * 2. The others that are a plain Firefly GET (the proxy, a controller's getAll/getOne) are fetched from Firefly all at
 *    once (Http::pool) into UpstreamPrefetch.
 * 3. Every read then runs through its route as if asked on its own, with all the middleware, so it is cached and
 *    answered exactly as before; a controller's GET to Firefly is answered from the prefetch. Reads Pocket works out
 *    itself (rule suggestions, search totals) run one after another, so the web app does not batch the slow ones.
 */
class BatchReader
{
    const MAX_PATHS = 50;
    // Parallel calls to Firefly at a time
    const CONCURRENCY = 8;
    const PREFETCHABLE = ['getAll', 'getOne', 'getBudgetLimits', 'proxyRequest'];

    public function __construct(private ResponseCache $cache, private UpstreamPrefetch $prefetch)
    {
    }

    /**
     * @param string[] $paths paths after /api/, with their query
     * @param string|null $owner keep the answers for this token hash instead of the caller's (CacheWarmUp)
     * @return array<string, array{status: int, body: string}> by path
     */
    public function read(Request $caller, array $paths, ?string $owner = null): array
    {
        $requests = [];
        foreach (array_values(array_unique($paths)) as $path) {
            $requests[$path] = $this->makeRequest($caller, $path, $owner);
        }

        $this->prefetchFromFirefly(array_filter($requests));
        try {
            $answers = [];
            foreach ($requests as $path => $request) {
                $answers[$path] = $request ? $this->dispatch($request) : ['status' => 400, 'body' => json_encode(['message' => 'Not a Firefly read'])];
            }
            return $answers;
        } finally {
            $this->prefetch->clear();
        }
    }

    /** A GET of one path as the caller would send it, or null when it is not a Firefly read. */
    private function makeRequest(Request $caller, string $path, ?string $owner): ?Request
    {
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '://') || str_contains($path, '..')) {
            return null;
        }
        $request = Request::create('/api/' . $path, 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => $caller->headers->get('Authorization'),
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => $caller->ip(),
        ]);
        $request->attributes->set('pocket.batch_item', true);
        if ($owner) {
            $request->attributes->set(ResponseCache::OWNER_ATTRIBUTE, $owner);
        }
        return $this->cache->isCacheable($request) ? $request : null;
    }

    private function prefetchFromFirefly(array $requests): void
    {
        $version = $this->cache->enabled() ? $this->cache->version() : null;
        $urls = [];
        foreach ($requests as $request) {
            if ($version !== null && $this->cache->get($version, $request)) {
                continue;
            }
            if ($this->isPlainFireflyRead($request) && ($url = BaseControllerFirefly::fireflyUrlFor($request))) {
                $urls[$url] = $request->headers->get('Authorization');
            }
        }

        foreach (array_chunk($urls, self::CONCURRENCY, true) as $chunk) {
            $responses = Http::pool(fn(Pool $pool) => array_map(
                fn($url, $authorization) => $pool->as($url)->withHeaders(['Authorization' => $authorization, 'Accept' => 'application/json'])->connectTimeout(10)->timeout(10)->get($url),
                array_keys($chunk), $chunk,
            ));
            foreach ($responses as $url => $response) {
                // A failed call is not kept: the controller asks Firefly itself and reports the error as usual
                if ($response instanceof ClientResponse && $response->successful()) {
                    $this->prefetch->put($chunk[$url], $url, $response->status(), ['Content-Type' => $response->header('Content-Type')], $response->body());
                }
            }
        }
    }

    private function isPlainFireflyRead(Request $request): bool
    {
        try {
            $route = Route::getRoutes()->match($request);
        } catch (Throwable) {
            return false;
        }
        [$controller, $method] = array_pad(explode('@', $route->getActionName()), 2, null);
        return in_array($method, self::PREFETCHABLE, true)
            && ($controller === FireflyProxyController::class || is_subclass_of($controller, BaseControllerFirefly::class));
    }

    /** Runs one read through the router, as the current request for the helpers that read request(). */
    private function dispatch(Request $request): array
    {
        // In the queue worker only the console kernel is booted: making the HTTP kernel registers the routes'
        // middleware groups ("api") with the router
        app(HttpKernel::class);
        $previous = app('request');
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');
        try {
            $response = app('router')->dispatch($request);
        } catch (Throwable $e) {
            $response = app(ExceptionHandler::class)->render($request, $e);
        } finally {
            app()->instance('request', $previous);
            Facade::clearResolvedInstance('request');
        }
        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()];
    }

    /** The answers as JSON without decoding them again: {"data": {"<path>": {"status", "size", "body"}}} */
    public static function toJson(array $answers): string
    {
        $parts = [];
        foreach ($answers as $path => $answer) {
            $body = $answer['body'];
            $json = $body !== '' && json_validate($body) ? $body : json_encode($body);
            $parts[] = json_encode((string) $path, JSON_UNESCAPED_SLASHES) . ':{"status":' . (int) $answer['status'] . ',"size":' . strlen($body) . ',"body":' . $json . '}';
        }
        return '{"data":{' . implode(',', $parts) . '}}';
    }
}
