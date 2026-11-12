<?php

namespace Tests\Unit;

use App\Services\Notifications\NotificationRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NotificationRecordTest extends TestCase
{
    private function record(array $extra = []): array
    {
        return NotificationRecord::fromMessage(['title' => 'T', 'body' => 'B'] + $extra);
    }

    public function test_a_message_without_an_event_is_an_alert_with_defaults()
    {
        $this->assertSame(
            ['kind' => 'alert', 'title' => 'T', 'body' => 'B', 'path' => '/', 'tag' => null, 'count' => null, 'quiet' => false],
            $this->record(),
        );
    }

    public function test_the_event_is_the_kind()
    {
        $this->assertSame('upgrade.success', $this->record(['event' => 'upgrade.success'])['kind']);
    }

    public function test_path_tag_and_count_are_kept()
    {
        $record = $this->record(['path' => '/bank-connections', 'tag' => 'import', 'count' => '3']);

        $this->assertSame('/bank-connections', $record['path']);
        $this->assertSame('import', $record['tag']);
        $this->assertSame(3, $record['count']);
    }

    public function test_the_senders_badge_is_not_kept()
    {
        $this->assertArrayNotHasKey('badge', $this->record(['badge' => 4]));
    }

    public static function quietCases(): array
    {
        return [
            'import success with news → quiet' => [['event' => 'import.success', 'count' => 3], true],
            'import success with count 0 → quiet' => [['event' => 'import.success', 'count' => 0], true],
            'import success with count "0" → quiet' => [['event' => 'import.success', 'count' => '0'], true],
            'import success without a count → quiet' => [['event' => 'import.success'], true],
            'import failure → counts' => [['event' => 'import.failure', 'count' => 0], false],
            'suggestions digest → counts' => [['event' => 'suggestions.digest'], false],
            'upgrade → counts' => [['event' => 'upgrade.success'], false],
            'alert → counts' => [[], false],
        ];
    }

    #[DataProvider('quietCases')]
    public function test_only_a_routine_import_success_is_quiet(array $extra, bool $quiet)
    {
        $this->assertSame($quiet, $this->record($extra)['quiet']);
    }

    public function test_title_and_body_are_cut_to_what_the_columns_hold()
    {
        $record = NotificationRecord::fromMessage(['title' => str_repeat('é', 120), 'body' => str_repeat('b', 350)]);

        $this->assertSame(100, mb_strlen($record['title']));
        $this->assertSame(300, mb_strlen($record['body']));
    }
}
