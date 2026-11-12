<?php

namespace App\Services\Notifications;

use App\Services\Push\PushPreferences;

/**
 * What the notifications center keeps of a message that came in at POST /api/internal/notify.
 */
class NotificationRecord
{
    const ALERT = 'alert';

    /**
     * $message: title, body, and optionally path, tag, event and count (the shape InternalNotifyController builds).
     * The sender's badge is not kept: the app badge is the unread count, which Pocket works out itself.
     */
    public static function fromMessage(array $message): array
    {
        $event = $message['event'] ?? null;

        return [
            'kind' => $event ?? self::ALERT,
            'title' => mb_substr((string) $message['title'], 0, 100),
            'body' => mb_substr((string) $message['body'], 0, 300),
            'path' => $message['path'] ?? '/',
            'tag' => $message['tag'] ?? null,
            'count' => isset($message['count']) ? (int) $message['count'] : null,
            // A routine good-news import is listed but never asks for attention, however many transactions it brought
            'quiet' => $event === PushPreferences::IMPORT_SUCCESS,
        ];
    }
}
