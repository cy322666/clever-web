<?php

namespace Tests\Feature\Core;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthenticationLogPruningScheduleTest extends TestCase
{
    public function test_authentication_log_purge_is_due_daily_at_0220_moscow_time(): void
    {
        $schedule = app(Schedule::class)->useCache('array');
        $event = collect($schedule->events())->sole(
            fn ($event): bool => str_contains((string) $event->command, 'authentication-log:purge'),
        );

        $this->assertSame('Europe/Moscow', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);

        foreach ([
            '2026-11-30 02:19' => false,
            '2026-11-30 02:20' => true,
            '2026-11-30 02:21' => false,
            '2026-12-01 02:20' => true,
            '2026-12-02 02:20' => true,
        ] as $time => $expected) {
            $this->travelTo(Carbon::parse($time, 'Europe/Moscow'));
            $this->assertSame($expected, $event->isDue($this->app) && $event->filtersPass($this->app), $time);
        }
    }

    public function test_purge_deletes_only_logins_strictly_older_than_ten_days(): void
    {
        config([
            'database.connections.auth_log_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'authentication-log.db_connection' => 'auth_log_test',
        ]);

        Schema::connection('auth_log_test')->create('authentication_log', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('login_at');
        });

        $this->travelTo(Carbon::parse('2026-12-01 02:20:00', 'Europe/Moscow'));
        $log = DB::connection('auth_log_test')->table('authentication_log');
        $log->insert([
            ['login_at' => '2026-11-21 02:19:59'],
            ['login_at' => '2026-11-21 02:20:00'],
            ['login_at' => '2026-11-21 02:20:01'],
        ]);

        $this->artisan('authentication-log:purge')->assertSuccessful();

        $this->assertSame([2, 3], $log->orderBy('id')->pluck('id')->all());
    }
}
