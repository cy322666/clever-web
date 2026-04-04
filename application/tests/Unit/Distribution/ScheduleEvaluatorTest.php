<?php

namespace Tests\Unit\Distribution;

use App\Services\Distribution\ScheduleEvaluator;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ScheduleEvaluatorTest extends TestCase
{
    public function test_returns_false_when_settings_are_empty(): void
    {
        $now = Carbon::parse('2026-04-06 10:00:00', 'Europe/Moscow');

        $this->assertFalse(ScheduleEvaluator::isWorkingNow(null, $now, 'Europe/Moscow'));
        $this->assertFalse(ScheduleEvaluator::isWorkingNow('[]', $now, 'Europe/Moscow'));
    }

    public function test_always_mode_returns_true(): void
    {
        $settings = json_encode([
            'mode' => 'always',
            'timezone' => 'Europe/Moscow',
        ], JSON_UNESCAPED_UNICODE);

        $now = Carbon::parse('2026-04-06 10:00:00', 'Europe/Moscow');

        $this->assertTrue(ScheduleEvaluator::isWorkingNow($settings, $now, 'UTC'));
    }

    public function test_weekly_rule_supports_overnight_shift(): void
    {
        $settings = json_encode([
            'mode' => 'weekly',
            'timezone' => 'Europe/Moscow',
            'weekly_rules' => [
                [
                    'day' => 1,
                    'from' => '22:00:00',
                    'to' => '02:00:00',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $mondayLate = Carbon::parse('2026-04-06 23:00:00', 'Europe/Moscow');
        $tuesdayEarly = Carbon::parse('2026-04-07 01:30:00', 'Europe/Moscow');
        $tuesdayLate = Carbon::parse('2026-04-07 03:00:00', 'Europe/Moscow');

        $this->assertTrue(ScheduleEvaluator::isWorkingNow($settings, $mondayLate, 'UTC'));
        $this->assertTrue(ScheduleEvaluator::isWorkingNow($settings, $tuesdayEarly, 'UTC'));
        $this->assertFalse(ScheduleEvaluator::isWorkingNow($settings, $tuesdayLate, 'UTC'));
    }

    public function test_cycle_mode_respects_work_and_rest_days_and_time_window(): void
    {
        $settings = json_encode([
            'mode' => 'cycle',
            'timezone' => 'Europe/Moscow',
            'cycle' => [
                'anchor_date' => '2026-04-01',
                'work_days' => 2,
                'rest_days' => 2,
                'from' => '09:00:00',
                'to' => '18:00:00',
            ],
        ], JSON_UNESCAPED_UNICODE);

        $workDayInsideHours = Carbon::parse('2026-04-02 10:00:00', 'Europe/Moscow');
        $workDayOutsideHours = Carbon::parse('2026-04-02 20:00:00', 'Europe/Moscow');
        $restDay = Carbon::parse('2026-04-03 10:00:00', 'Europe/Moscow');

        $this->assertTrue(ScheduleEvaluator::isWorkingNow($settings, $workDayInsideHours, 'UTC'));
        $this->assertFalse(ScheduleEvaluator::isWorkingNow($settings, $workDayOutsideHours, 'UTC'));
        $this->assertFalse(ScheduleEvaluator::isWorkingNow($settings, $restDay, 'UTC'));
    }

    public function test_exceptions_have_priority_over_base_schedule(): void
    {
        $weeklyWithOverrides = json_encode([
            'mode' => 'weekly',
            'timezone' => 'Europe/Moscow',
            'weekly_rules' => [
                [
                    'day' => 1,
                    'from' => '09:00:00',
                    'to' => '18:00:00',
                ],
            ],
            'exceptions' => [
                [
                    'type' => 'work',
                    'from' => '2026-04-06 20:00:00',
                    'to' => '2026-04-06 21:00:00',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $workByException = Carbon::parse('2026-04-06 20:30:00', 'Europe/Moscow');
        $this->assertTrue(ScheduleEvaluator::isWorkingNow($weeklyWithOverrides, $workByException, 'UTC'));

        $alwaysWithConflictingOverrides = json_encode([
            'mode' => 'always',
            'timezone' => 'Europe/Moscow',
            'exceptions' => [
                [
                    'type' => 'work',
                    'from' => '2026-04-06 10:00:00',
                    'to' => '2026-04-06 12:00:00',
                ],
                [
                    'type' => 'free',
                    'from' => '2026-04-06 10:30:00',
                    'to' => '2026-04-06 11:30:00',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $insideConflict = Carbon::parse('2026-04-06 11:00:00', 'Europe/Moscow');
        $this->assertFalse(ScheduleEvaluator::isWorkingNow($alwaysWithConflictingOverrides, $insideConflict, 'UTC'));
    }

    public function test_invalid_settings_timezone_falls_back_to_runtime_timezone(): void
    {
        $settings = json_encode([
            'mode' => 'weekly',
            'timezone' => 'Invalid/Timezone',
            'weekly_rules' => [
                [
                    'day' => 1,
                    'from' => '09:00:00',
                    'to' => '18:00:00',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $mondayUtc = Carbon::parse('2026-04-06 10:00:00', 'UTC');

        $this->assertTrue(ScheduleEvaluator::isWorkingNow($settings, $mondayUtc, 'UTC'));
    }
}
