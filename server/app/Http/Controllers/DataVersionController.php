<?php

namespace App\Http\Controllers;

use App\Authorizations\BaseAuthorization;
use App\Http\Controllers\Base\BaseController;
use App\Services\ResponseCache;

/**
 * The response cache's data version, for the web app's own cache: answers it kept under this version are still
 * right. version is null when the cache is off, and then nothing may be kept. ttl is how long an answer is
 * trusted at most, for changes no one told Pocket about.
 */
class DataVersionController extends BaseController
{
    public function get()
    {
        BaseAuthorization::checkUser();
        return $this->current();
    }

    /**
     * Settings → Sync everything: starts a new version, so what Pocket and every browser kept of Firefly's data is
     * out of date and the next read of each page goes to Firefly. Answers with the new version. The middleware leaves
     * this route's version to us (ResponseCache::NO_CHANGE), so it moves once.
     */
    public function renew()
    {
        BaseAuthorization::checkUser();
        app(ResponseCache::class)->bump();
        return $this->current();
    }

    private function current()
    {
        $cache = app(ResponseCache::class);
        return $this->respond(['data' => [
            'version' => $cache->enabled() ? (string) $cache->version() : null,
            'ttl' => (int) config('app.response_cache_ttl'),
        ]]);
    }
}
