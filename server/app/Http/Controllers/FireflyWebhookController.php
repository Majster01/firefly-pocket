<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Base\BaseController;
use App\Services\ResponseCache;
use Illuminate\Http\Request;

/**
 * Firefly III calls this from a webhook (transaction stored, updated or deleted), so changes that do not go through
 * Pocket (Firefly's own pages, the data importer) start a new data version of the response cache at once.
 * Only the signature is checked and nothing is read from the message: all it does is empty the cache.
 * The reverse proxy must not expose /api/internal/* publicly.
 */
class FireflyWebhookController extends BaseController
{
    public function receive(Request $request)
    {
        $secret = (string) config('app.firefly_webhook_secret');
        if ($secret === '') {
            return $this->fail('FIREFLY_WEBHOOK_SECRET is not set', self::HTTP_CODE_SERVICE_UNAVAILABLE);
        }
        if (!$this->isSigned((string) $request->header('Signature'), $request->getContent(), $secret)) {
            return $this->fail('Unauthorized', self::HTTP_CODE_UNAUTHORIZED);
        }

        app(ResponseCache::class)->bump();
        return $this->respond(['message' => 'OK']);
    }

    /**
     * Firefly's header is "t=<unix time>,v1=<hmac>", the HMAC being SHA3-256 of "<t>.<body>" with the webhook's secret
     * (FireflyIII\Helpers\Webhook\Sha3SignatureGenerator).
     */
    private function isSigned(string $header, string $body, string $secret): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }
        if (!isset($parts['t'], $parts['v1']) || $parts['t'] === '' || $parts['v1'] === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha3-256', $parts['t'] . '.' . $body, $secret), $parts['v1']);
    }

    private function fail($message, $statusCode)
    {
        return $this->setStatusCode($statusCode)->respond(['message' => $message]);
    }
}
