<?php

namespace App\Http\Middleware;

use App\Support\RequestTiming;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-Timing on every API answer, shown in the browser's network panel:
 *   total     the whole request in Pocket, from PHP starting (LARAVEL_START)
 *   upstream  waiting on Firefly and the importer, summed, with the number of calls
 *   cache     hit, miss or bypass (CacheFireflyResponses)
 * With SERVER_TIMING_LOG=true each request is also logged, to compare before and after on a real server.
 */
class ServerTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        // A read inside POST /api/batch: the batch request is timed as a whole
        if (!config('app.server_timing') || $request->attributes->get('pocket.batch_item')) {
            return $next($request);
        }
        $start = defined('LARAVEL_START') ? LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));
        // A fresh count for this request, whatever the server keeps between requests
        $timing = new RequestTiming();
        app()->instance(RequestTiming::class, $timing);

        $response = $next($request);

        $total = (microtime(true) - $start) * 1000;
        $cache = $response->headers->get('X-Pocket-Cache');
        $metrics = [
            sprintf('total;dur=%.1f', $total),
            sprintf('upstream;dur=%.1f;desc="%d call%s"', $timing->upstreamMs, $timing->upstreamCalls, $timing->upstreamCalls === 1 ? '' : 's'),
        ];
        if ($cache) {
            $metrics[] = "cache;desc=$cache";
        }
        $response->headers->set('Server-Timing', implode(', ', $metrics));

        if (config('app.server_timing_log')) {
            Log::info(sprintf('timing %s /%s %d total=%.0fms upstream=%.0fms calls=%d cache=%s',
                $request->method(), $request->path(), $response->getStatusCode(), $total, $timing->upstreamMs, $timing->upstreamCalls, $cache ?? '-'));
        }
        return $response;
    }
}
