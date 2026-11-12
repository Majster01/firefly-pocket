<?php

namespace App\Services;

use App\Models\InboxNotification;
use App\Models\InboxNotificationRead;
use App\Services\Notifications\NotificationRecord;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The notifications center: keeps every message that came in at POST /api/internal/notify, whatever a device's push
 * settings are, and who has read what. The messages belong to the instance (push goes to every device too); reads are per user.
 * What counts as unread is every message that is not quiet and that the user has not read.
 */
class NotificationCenterService
{
    const RETENTION_DAYS = 90;
    const MAX_ROWS = 500;

    /**
     * Keeps the message and drops what is too old or beyond the newest MAX_ROWS.
     * A tag that is already there is replaced, unless the text is the same: then the row, and who read it, stays.
     */
    public function store(array $message): InboxNotification
    {
        $attributes = NotificationRecord::fromMessage($message);
        try {
            $notification = $this->write($attributes);
        } catch (UniqueConstraintViolationException $e) {
            // Two messages with the same tag at once: the other one won, replace it
            Log::warning('Notification tag collided, trying again', ['tag' => $attributes['tag']]);
            $notification = $this->write($attributes);
        }
        $this->prune(except: $notification->id);

        return $notification;
    }

    private function write(array $attributes): InboxNotification
    {
        return DB::transaction(function () use ($attributes) {
            $existing = $attributes['tag'] === null ? null : InboxNotification::where('tag', $attributes['tag'])->first();
            if ($existing && $existing->title === $attributes['title'] && $existing->body === $attributes['body']) {
                Log::info('Notification kept, same text as before', ['id' => $existing->id, 'tag' => $attributes['tag']]);
                return $existing;
            }
            // A new row, not an update: ids keep rising, so the newest message is the first of the list, and unread again
            $existing?->delete();
            $notification = InboxNotification::create($attributes);
            Log::info('Notification stored', ['id' => $notification->id, 'kind' => $attributes['kind'], 'tag' => $attributes['tag'], 'quiet' => $attributes['quiet'], 'replaced' => $existing?->id]);

            return $notification;
        });
    }

    /** $except: the message just stored or kept, which a repeated daily digest must not lose to its own age */
    private function prune(int $except): void
    {
        $old = InboxNotification::where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->where('id', '!=', $except)->delete();
        $beyondNewest = InboxNotification::orderByDesc('id')->skip(self::MAX_ROWS)->value('id');
        $surplus = $beyondNewest ? InboxNotification::where('id', '<=', $beyondNewest)->where('id', '!=', $except)->delete() : 0;
        if ($old || $surplus) {
            Log::info('Notifications pruned', ['expired' => $old, 'beyond_newest' => $surplus, 'retention_days' => self::RETENTION_DAYS, 'max_rows' => self::MAX_ROWS]);
        }
    }

    /**
     * One page, newest first. $before: the id the previous page ended with.
     * Returns the messages (with read for this user), the next $before or null, and the unread count.
     */
    public function page(string $userId, ?int $before, int $limit): array
    {
        $rows = InboxNotification::query()
            ->when($before, fn($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();

        $read = InboxNotificationRead::where('user_id', $userId)->whereIn('notification_id', $rows->pluck('id'))->pluck('notification_id')->map(fn($id) => (int) $id)->all();
        $data = $rows->map(fn($row) => [
            'id' => $row->id,
            'kind' => $row->kind,
            'title' => $row->title,
            'body' => $row->body,
            'path' => $row->path,
            'count' => $row->count,
            'quiet' => $row->quiet,
            'read' => in_array($row->id, $read, true),
            'created_at' => $row->created_at,
        ])->all();

        return ['data' => $data, 'next' => $more ? $rows->last()->id : null, 'unread' => $this->unreadCount($userId)];
    }

    public function unreadCount(string $userId): int
    {
        return $this->unreadCounts([$userId])[$userId];
    }

    /** @return array<string,int> the unread count of each user */
    public function unreadCounts(array $userIds): array
    {
        $total = InboxNotification::where('quiet', false)->count();
        $read = InboxNotificationRead::query()
            ->join('inbox_notifications', 'inbox_notifications.id', '=', 'inbox_notification_reads.notification_id')
            ->where('inbox_notifications.quiet', false)
            ->whereIn('inbox_notification_reads.user_id', $userIds)
            ->groupBy('inbox_notification_reads.user_id')
            ->selectRaw('inbox_notification_reads.user_id as user_id, count(*) as read_count')
            ->pluck('read_count', 'user_id');

        $counts = [];
        foreach ($userIds as $userId) {
            $counts[$userId] = max(0, $total - (int) ($read[$userId] ?? 0));
        }

        return $counts;
    }

    /** False when there is no such message (pruned, or never there). Reading twice changes nothing. */
    public function markRead(string $userId, int $id): bool
    {
        if (!InboxNotification::whereKey($id)->exists()) {
            return false;
        }
        InboxNotificationRead::insertOrIgnore([['user_id' => $userId, 'notification_id' => $id, 'read_at' => now()]]);

        return true;
    }

    /** $upTo: the newest id the user was shown, so a message that arrived meanwhile stays unread. */
    public function markAllRead(string $userId, ?int $upTo): void
    {
        $alreadyRead = InboxNotificationRead::where('user_id', $userId)->select('notification_id');
        $ids = InboxNotification::query()
            ->where('quiet', false)
            ->when($upTo !== null, fn($query) => $query->where('id', '<=', $upTo))
            ->whereNotIn('id', $alreadyRead)
            ->pluck('id');
        $now = now();
        InboxNotificationRead::insertOrIgnore($ids->map(fn($id) => ['user_id' => $userId, 'notification_id' => $id, 'read_at' => $now])->all());
        Log::info('Notifications marked read', ['user_id' => $userId, 'count' => $ids->count(), 'up_to' => $upTo]);
    }
}
