<?php

namespace App\Console\Commands\Core;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class SmokeCheck extends Command
{
    protected $signature = 'app:smoke {--strict : Treat warnings as errors}';

    protected $description = 'Run post-deploy smoke checks for core dependencies and routes';

    public function handle(): int
    {
        $errors = [];
        $warnings = [];

        try {
            DB::select('select 1');
        } catch (\Throwable $e) {
            $errors[] = 'DB connection failed: ' . $e->getMessage();
        }

        foreach (['jobs', 'failed_jobs', 'queue_monitors'] as $table) {
            try {
                if (!Schema::hasTable($table)) {
                    $errors[] = "Missing table: {$table}";
                }
            } catch (\Throwable $e) {
                $errors[] = "Failed to inspect table {$table}: {$e->getMessage()}";
            }
        }

        foreach (['up', 'metrics', 'filament.app.pages.dashboard'] as $routeName) {
            if (!Route::has($routeName)) {
                $warnings[] = "Route not found: {$routeName}";
            }
        }

        if ((string)config('queue.default') === 'sync' && app()->environment('production')) {
            $warnings[] = 'QUEUE_CONNECTION=sync in production.';
        }

        $heartbeatTs = (int)Cache::get('monitoring:scheduler:last_heartbeat', 0);

        if ($heartbeatTs <= 0) {
            $warnings[] = 'Scheduler heartbeat is missing.';
        } else {
            $age = max(0, now()->timestamp - $heartbeatTs);

            if ($age > 180) {
                $warnings[] = 'Scheduler heartbeat is stale: ' . $age . 's';
            }
        }

        if ($errors === [] && $warnings === []) {
            $this->info('Smoke check passed.');

            return self::SUCCESS;
        }

        if ($errors !== []) {
            $this->error('Errors:');
            foreach ($errors as $error) {
                $this->line(' - ' . $error);
            }
        }

        if ($warnings !== []) {
            $this->warn('Warnings:');
            foreach ($warnings as $warning) {
                $this->line(' - ' . $warning);
            }
        }

        if ($errors !== [] || ($warnings !== [] && (bool)$this->option('strict'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
