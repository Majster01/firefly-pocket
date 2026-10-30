<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function fake($yahoo)
    {
        Http::fake(function ($request) use ($yahoo) {
            $url = $request->url();
            if (str_contains($url, 'about/user')) {
                return Http::response(['data' => ['id' => '1']]);
            }
            if (str_contains($url, 'open.er-api.com')) {
                return Http::response(['time_last_update_utc' => 'Fri, 30 Oct 2026 00:02:31 +0000', 'rates' => ['EUR' => 1, 'USD' => 1.1]]);
            }
            if (str_contains($url, 'fxratesapi.com')) {
                return Http::response(['rates' => ['EUR' => 1, 'USD' => 1.1, 'BTC' => 0.00001]]);
            }
            return $yahoo($request);
        });
    }

    private function getRates()
    {
        return $this->getJson('api/currencies/exchange', ['Authorization' => 'Bearer test-token']);
    }

    public function test_rates_are_relative_to_euro()
    {
        $this->fake(fn() => Http::response(null, 500));

        $this->getRates()->assertOk()->assertJsonPath('rates.EUR', 1);

        Http::assertSent(fn($request) => str_ends_with($request->url(), 'open.er-api.com/v6/latest/EUR'));
        Http::assertSent(fn($request) => str_contains($request->url(), 'fxratesapi.com/latest?base=EUR'));
    }

    public function test_vwce_is_the_inverse_of_its_euro_price()
    {
        $this->fake(fn() => Http::response(['chart' => ['result' => [['meta' => ['regularMarketPrice' => 125.0]]]]]));

        $this->getRates()->assertOk()->assertJsonPath('rates.VWCE', 0.008);

        Http::assertSent(fn($request) => str_contains($request->url(), 'chart/VWCE.DE'));
    }

    public function test_a_failed_vwce_lookup_only_leaves_vwce_out()
    {
        $this->fake(fn() => Http::response(null, 429));

        $this->getRates()->assertOk()->assertJsonPath('rates.USD', 1.1)->assertJsonMissingPath('rates.VWCE');
    }
}
