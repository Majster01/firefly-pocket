<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Carbon\CarbonInterface;

/**
 * What one device wants to be notified about. The FireflyX senders name the kind of message in `event`;
 * a message without an event is an alert (backup, consent or deploy failure) and always goes out.
 */
class PushPreferences
{
    const IMPORT_SUCCESS = 'import.success';
    const IMPORT_FAILURE = 'import.failure';
    const SUGGESTIONS = 'suggestions.digest';
    const UPGRADE_SUCCESS = 'upgrade.success';

    const EVENTS = [self::IMPORT_SUCCESS, self::IMPORT_FAILURE, self::SUGGESTIONS, self::UPGRADE_SUCCESS];
    const FREQUENCIES = ['off', 'daily', 'weekly'];

    public static function defaults(): array
    {
        return [
            'import_success' => true,
            // A night with nothing new is not worth a notification unless asked for
            'import_success_only_new' => true,
            'import_failure' => true,
            'upgrade_success' => true,
            // weekday: 1 = Monday ... 7 = Sunday
            'suggestions' => ['frequency' => 'weekly', 'weekday' => 1],
        ];
    }

    /** Stored preferences laid over the defaults, so a key added later still has a value. */
    public static function for(PushSubscription $subscription): array
    {
        return self::merge($subscription->preferences ?? []);
    }

    public static function merge(array $input): array
    {
        $defaults = self::defaults();
        $result = $defaults;
        foreach (['import_success', 'import_success_only_new', 'import_failure', 'upgrade_success'] as $key) {
            if (array_key_exists($key, $input)) {
                $result[$key] = (bool) $input[$key];
            }
        }
        $suggestions = is_array($input['suggestions'] ?? null) ? $input['suggestions'] : [];
        if (in_array($suggestions['frequency'] ?? null, self::FREQUENCIES, true)) {
            $result['suggestions']['frequency'] = $suggestions['frequency'];
        }
        if (isset($suggestions['weekday']) && (int) $suggestions['weekday'] >= 1 && (int) $suggestions['weekday'] <= 7) {
            $result['suggestions']['weekday'] = (int) $suggestions['weekday'];
        }
        return $result;
    }

    /**
     * Should this device get a message of this kind?
     * $count is the number of new transactions of an import, when the sender says.
     */
    public static function wants(PushSubscription $subscription, ?string $event, ?int $count, CarbonInterface $now): bool
    {
        $preferences = self::for($subscription);
        return match ($event) {
            self::IMPORT_SUCCESS => $preferences['import_success'] && !($preferences['import_success_only_new'] && $count === 0),
            self::IMPORT_FAILURE => $preferences['import_failure'],
            self::UPGRADE_SUCCESS => $preferences['upgrade_success'],
            self::SUGGESTIONS => match ($preferences['suggestions']['frequency']) {
                'daily' => true,
                'weekly' => $now->isoWeekday() === $preferences['suggestions']['weekday'],
                default => false,
            },
            default => true,
        };
    }
}
