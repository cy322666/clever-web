<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SmokeCheckRuntimeAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'production');
        config(['queue.default' => 'redis', 'alerts.cache_store' => 'array']);
        Cache::store('array')->put('monitoring:scheduler:last_heartbeat', time());
        Schema::shouldReceive('hasTable')->andReturnTrue();
        DB::shouldReceive('select')->with('select 1')->andReturn([]);
        Route::shouldReceive('has')->andReturnTrue();
    }

    public function test_unreadable_source_fails_an_otherwise_healthy_smoke_check(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Unreadable runtime file: app/Example.php', exitCode: 1)]);
        $this->artisan('app:smoke', ['--strict' => true])
            ->expectsOutputToContain('Unreadable runtime file: app/Example.php')
            ->assertExitCode(1);
        Process::assertRan(fn ($process) => $process->command === [PHP_BINARY, base_path('scripts/check-runtime-access.php')]);
    }

    public function test_readable_source_passes(): void
    {
        Process::fake(['*' => Process::result(output: 'Runtime access check passed.')]);
        $this->artisan('app:smoke', ['--strict' => true])
            ->expectsOutput('Smoke check passed.')
            ->assertExitCode(0);
    }

    public function test_a_failed_access_probe_is_not_silently_ignored(): void
    {
        Process::fake(fn () => throw new \RuntimeException('probe unavailable'));
        $this->artisan('app:smoke', ['--strict' => true])
            ->expectsOutputToContain('Runtime source access check failed: probe unavailable')
            ->assertExitCode(1);
    }
}
