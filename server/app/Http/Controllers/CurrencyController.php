<?php

namespace App\Http\Controllers;

use App\Exceptions\GeneralException;
use App\Http\Controllers\Base\BaseController;
use App\Http\Controllers\Base\BaseControllerFirefly;
use App\Utils\CurrencyUtils;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class CurrencyController extends BaseControllerFirefly
{
    // Xetra (EUR) line, the same one Interactive Brokers lists as VWCE on IBIS2
    private const VWCE_SYMBOL = 'VWCE.DE';

    public function __construct()
    {
        parent::__construct("/api/v1/currencies");
    }

    // ---------------------------


    public function exchangeRates()
    {
        if (!getUser()) {
            throw new GeneralException("Unauthorized", BaseController::HTTP_CODE_UNAUTHORIZED);
        }

        $date = Carbon::now()->startOfDay()->format("Y-m-d");
        $cacheKey = "exchange_eur_$date";
        $exchange = Cache::remember($cacheKey, 60 * 60 * 24 * 5, function () {
            $rates = collect();
            $updatedAt = null;

            // Fetch rates from ExchangeRate API
            $exchangeRateAPIResponse = Http::get("https://open.er-api.com/v6/latest/EUR");

            if ($exchangeRateAPIResponse->status() === self::HTTP_CODE_OK) {
                $exchangeRateAPIBody = $exchangeRateAPIResponse->json();
                $exchangeRateAPIRates = collect(fget($exchangeRateAPIBody, 'rates'));
                $rates = $rates->merge($exchangeRateAPIRates);
                $updatedAt = Carbon::parse(fget($exchangeRateAPIBody, 'time_last_update_utc'))->startOfDay()->format("Y-m-d");
            }


            // Fetch rates from FXRates API
            $fxRatesAPIResponse = Http::get("https://api.fxratesapi.com/latest?base=EUR");
            if ($fxRatesAPIResponse->status() === self::HTTP_CODE_OK) {
                $fxRates = collect(fget($fxRatesAPIResponse->json(), 'rates'));
                // Merge rates, prioritizing rates from the first API
                $rates = $fxRates->merge($rates);
            }

            return [
                'date' => $updatedAt,
                'rates' => $rates,
                'currencies' => CurrencyUtils::CURRENCIES
            ];
        });

        // Rates are units per 1 EUR, so VWCE (shares) is the inverse of its EUR price
        $vwcePrice = $this->vwcePrice($date);
        if ($vwcePrice) {
            $exchange['rates'] = collect($exchange['rates'])->put('VWCE', 1 / $vwcePrice);
        }

        return $exchange;
    }

    private function vwcePrice(string $date): ?float
    {
        // A null result is not cached by Laravel, so a failed lookup is retried on the next request
        return Cache::remember("vwce_price_$date", 60 * 60 * 24, function () {
            $response = Http::withUserAgent('Mozilla/5.0')->get("https://query1.finance.yahoo.com/v8/finance/chart/" . self::VWCE_SYMBOL . "?interval=1d&range=1d");
            $price = $response->status() === self::HTTP_CODE_OK ? fget($response->json(), 'chart.result.0.meta.regularMarketPrice') : null;

            return is_numeric($price) && $price > 0 ? (float)$price : null;
        });
    }


}
