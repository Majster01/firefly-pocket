<?php

namespace App\Support;

/**
 * Time this request spent waiting on outgoing HTTP calls (Firefly, the importer), for the Server-Timing header.
 * Calls made in parallel (Http::pool) add up, so the sum can be more than the wall time.
 */
class RequestTiming
{
    public int $upstreamCalls = 0;
    public float $upstreamMs = 0;

    public function addUpstream(float $ms): void
    {
        $this->upstreamCalls++;
        $this->upstreamMs += $ms;
    }
}
