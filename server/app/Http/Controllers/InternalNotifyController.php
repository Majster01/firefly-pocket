<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Base\BaseController;
use App\Services\NotificationCenterService;
use App\Services\Notifications\NotificationRecord;
use App\Services\PushNotificationService;
use App\Services\ResponseCache;
use App\Validations\PushValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;


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

        // The importer and the suggester call this after a run: what they wrote into Firefly must not wait for the TTL
        app(ResponseCache::class)->bump();

        $validator = PushValidation::makeNotifyValidator($request);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), self::HTTP_CODE_UNPROCESSABLE_ENTITY, ['errors' => $validator->errors()]);
        }

        $data = $validator->validated();
        $message = ['title' => $data['title'], 'body' => $data['body'], 'path' => $data['path'] ?? '/'];
        if (isset($data['tag'])) {
            $message['tag'] = $data['tag'];
        }
        foreach (['event', 'count'] as $key) {
            if (isset($data[$key])) {
                $message[$key] = $data[$key];
            }
        }

        // Kept whatever the push does: the center is the record of what was sent, with push switched off too
        $center = app(NotificationCenterService::class);
        $stored = $this->store($center, $message);

        $service = app(PushNotificationService::class);
        if (!$service->isConfigured()) {
            return $this->fail('Push is not configured (VAPID keys missing)', self::HTTP_CODE_SERVICE_UNAVAILABLE, ['stored' => $stored]);
        }

        // The id lets a tap on the notification mark it read. A badge from the sender is ignored: it is the unread count now,
        // and without a readable center there is neither an id nor a count to send
        if ($stored !== null) {
            $message['id'] = $stored;
        }
        return $this->respond($service->notify($message, $stored === null ? null : fn(array $userIds) => $this->unreadCounts($center, $userIds)));
    }

    /** Null when the center cannot be read: the push then goes out without a badge. */
    private function unreadCounts(NotificationCenterService $center, array $userIds): ?array
    {
        try {
            return $center->unreadCounts($userIds);
        } catch (Throwable $e) {
            Log::warning('Unread counts could not be read, pushing without a badge', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** The id of the stored notification, or null: a center that fails must never keep an alert from the phone. */
    private function store(NotificationCenterService $center, array $message): ?int
    {
        try {
            return $center->store($message)->id;
        } catch (Throwable $e) {
            Log::error('Notification could not be stored', ['kind' => $message['event'] ?? NotificationRecord::ALERT, 'tag' => $message['tag'] ?? null, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function fail($message, $statusCode, $extra = [])
    {
        return $this->setStatusCode($statusCode)->respond(['message' => $message] + $extra);
    }
}
