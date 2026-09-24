<?php

namespace Tests\Unit\Finder;

use App\Services\Finder\WorkingTime;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class WorkingTimeTest extends TestCase
{
    private function deadline(string $from, int $minutes, array $schedule, string $timezone = 'Europe/Moscow'): string
    {
        return (new WorkingTime)->deadline(CarbonImmutable::parse($from, 'UTC'), $minutes * 60, [
            'working_time' => true, 'timezone' => $timezone, 'schedule' => $schedule,
        ])->format('Y-m-d H:i');
    }

    public function test_weekend_pause_preserves_remaining_working_minutes(): void
    {
        $this->assertSame('2026-09-28 06:03', $this->deadline('2026-09-25 16:58', 5, [['days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '20:00']]));
    }

    public function test_lunch_breaks_and_overlaps_do_not_count_twice(): void
    {
        $rows = [['days' => [4], 'from' => '09:00', 'to' => '12:00'], ['days' => [4], 'from' => '11:00', 'to' => '12:00'], ['days' => [4], 'from' => '13:00', 'to' => '20:00']];
        $this->assertSame('2026-09-24 10:30', $this->deadline('2026-09-24 08:30', 60, $rows));
    }

    public function test_shift_crossing_midnight_and_full_day(): void
    {
        $this->assertSame('2026-09-25 00:05', $this->deadline('2026-09-24 23:55', 10, [['days' => [4], 'from' => '22:00', 'to' => '06:00']]));
        $this->assertSame('2026-09-25 02:10', $this->deadline('2026-09-24 23:55', 135, [['days' => [5], 'from' => '00:00', 'to' => '00:00']]));
    }

    public function test_dst_uses_elapsed_seconds_and_preserves_callers_timezone(): void
    {
        $this->assertSame('2026-10-25 02:30', $this->deadline('2026-10-25 00:30', 120, [['days' => [7], 'from' => '01:00', 'to' => '05:00']], 'Europe/Berlin'));
    }

    public function test_without_schedule_counts_calendar_time(): void
    {
        $from = CarbonImmutable::parse('2026-09-25 19:58', 'Europe/Moscow');
        $this->assertSame('2026-09-25T20:03:00+03:00', (new WorkingTime)->deadline($from, 300, ['working_time' => false])->toIso8601String());
    }
}
