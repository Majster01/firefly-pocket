<?php

namespace App\Jobs;

use App\Services\BatchReader;
use App\Services\CacheWarmUp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/** See CacheWarmUp: fetches again what Home asked for, after the data version moved on. */
class WarmUpResponseCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Below the Horizon worker timeout in config/horizon.php
    public $timeout = 120;
    public $tries = 1;

    public function handle(CacheWarmUp $warmUp, BatchReader $reader): void
    {
        $count = $warmUp->run($reader);
        Log::info("Cache warm-up: $count answers kept");
    }
}
