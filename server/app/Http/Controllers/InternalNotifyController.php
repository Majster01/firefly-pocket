<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Base\BaseController;
use App\Services\PushNotificationService;
use App\Validations\PushValidation;
use Illuminate\Http\Request;


/**
 * Called by the FireflyX importer/suggester over the compose network, with a shared token instead of a user login.
 * The reverse proxy must not expose /api/internal/* publicly.
 */
class InternalNotifyController extends BaseController
{
    // A shorter token is not a secret worth trusting
    const MIN_TOKEN_LENGTH = 32;

    public function notify(Request $request)
    {
        $token = (string) config('app.notify_api_token');
        if (strlen($token) < self::MIN_TOKEN_LENGTH) {
            return $this->fail('NOTIFY_API_TOKEN is not set (at least ' . self::MIN_TOKEN_LENGTH . ' characters)', self::HTTP_CODE_SERVICE_UNAVAILABLE);
        }
        if (!hash_equals($token, (string) $request->bearerToken())) {
            return $this->fail('Unauthorized', self::HTTP_CODE_UNAUTHORIZED);
        }

        $service = app(PushNotificationService::class);
        if (!$service->isConfigured()) {
            return $this->fail('Push is not configured (VAPID keys missing)', self::HTTP_CODE_SERVICE_UNAVAILABLE);
        }

        $validator = PushValidation::makeNotifyValidator($request);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), self::HTTP_CODE_UNPROCESSABLE_ENTITY, ['errors' => $validator->errors()]);
        }

        $data = $validator->validated();
        $message = ['title' => $data['title'], 'body' => $data['body'], 'path' => $data['path'] ?? '/'];
        if (isset($data['badge'])) {
            $message['badge'] = (int) $data['badge'];
        }
        if (isset($data['tag'])) {
            $message['tag'] = $data['tag'];
        }
        return $this->respond($service->notify($message));
    }

    private function fail($message, $statusCode, $extra = [])
    {
        return $this->setStatusCode($statusCode)->respond(['message' => $message] + $extra);
    }
}
