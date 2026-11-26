<?php

namespace Tests\Feature\Core;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AuthenticationLogPruningScheduleTest extends TestCase
{
    public function test_authentication_log_purge_is_due_every_ten_moscow_calendar_days(): void
    {
        $event = collect(app(Schedule::class)->events())->sole(
            fn ($event): bool => str_contains((string) $event->command, 'authentication-log:purge'),
        );

        $this->assertSame('Europe/Moscow', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);

        foreach ([
            '2026-11-26 02:20' => false,
            '2026-11-27 02:19' => false,
            '2026-11-27 02:20' => true,
            '2026-12-01 02:20' => false,
            '2026-12-07 02:20' => true,
            '2026-12-08 02:20' => false,
            '2026-12-17 02:20' => true,
            '2026-12-27 02:20' => true,
            '2027-01-06 02:20' => true,
        ] as $time => $expected) {
            $this->travelTo(Carbon::parse($time, 'Europe/Moscow'));
            $this->assertSame($expected, $event->isDue($this->app) && $event->filtersPass($this->app), $time);
        }
    }
}
