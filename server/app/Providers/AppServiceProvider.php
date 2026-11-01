<?php

namespace App\Providers;

use App\Services\Push\PushSender;
use App\Services\Push\WebPushSender;
use App\Support\RequestTiming;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PushSender::class, WebPushSender::class);
        // Per request, also under a long-running server (Octane)
        $this->app->scoped(RequestTiming::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Times every outgoing call (Firefly, the importer) for the Server-Timing header
        Http::globalMiddleware(function (callable $handler) {
            return function ($request, array $options) use ($handler) {
                $start = hrtime(true);
                $record = fn() => app(RequestTiming::class)->addUpstream((hrtime(true) - $start) / 1e6);
                return $handler($request, $options)->then(
                    function ($response) use ($record) {
                        $record();
                        return $response;
                    },
                    function ($reason) use ($record) {
                        $record();
                        return Create::rejectionFor($reason);
                    },
                );
            };
        });
    }
}
