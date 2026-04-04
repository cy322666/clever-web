<?php

namespace App\Filament\App\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueOpsOverview extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Операционные метрики очередей';

    protected ?string $description = 'Только показатели, которые не дублируются в Grafana';

    protected function getStats(): array
    {
        $failedConnection = (string)(config('queue.failed.database') ?? config('database.default'));
        $monitorConnection = (string)(config('filament-jobs-monitor.connection') ?? config('database.default'));
        $failedTable = (string)config('queue.failed.table', 'failed_jobs');

        $unsynced = $this->getUnsyncedFailedJobsCount($failedConnection, $monitorConnection, $failedTable);

        $backfillLastRun = (int)Cache::get('monitoring:queue:backfill:last_run', 0);
        $backfillScanned = (int)Cache::get('monitoring:queue:backfill:last_scanned', 0);
        $backfillInserted = (int)Cache::get('monitoring:queue:backfill:last_inserted', 0);

        $healthLastRun = (int)Cache::get('monitoring:queue:health:last_run', 0);
        $healthNewFailed = (int)Cache::get('monitoring:queue:health:last_new_failed', 0);
        $healthStuck = (int)Cache::get('monitoring:queue:health:last_stuck', 0);

        return [
            Stat::make('Не синхронизированы', $unsynced === null ? 'n/a' : (string)$unsynced)
                ->description(
                    $unsynced === null
                        ? 'Нельзя посчитать: разные DB connections или нет таблиц'
                        : 'failed_jobs без записи в queue_monitors'
                )
                ->color($unsynced === null ? 'gray' : ($unsynced > 0 ? 'warning' : 'success')),

            Stat::make('Последний backfill', $this->formatTimestampAgo($backfillLastRun))
                ->description('scanned: ' . $backfillScanned . ', inserted: ' . $backfillInserted)
                ->color('info'),

            Stat::make('Последний queue health-check', $this->formatTimestampAgo($healthLastRun))
                ->description('new_failed: ' . $healthNewFailed . ', stuck: ' . $healthStuck)
                ->color(($healthNewFailed > 0 || $healthStuck > 0) ? 'warning' : 'gray'),
        ];
    }

    private function getUnsyncedFailedJobsCount(
        string $failedConnection,
        string $monitorConnection,
        string $failedTable
    ): ?int {
        if ($failedConnection !== $monitorConnection) {
            return null;
        }

        if (!Schema::connection($failedConnection)->hasTable($failedTable)) {
            return null;
        }

        if (!Schema::connection($monitorConnection)->hasTable('queue_monitors')) {
            return null;
        }

        return (int)DB::connection($failedConnection)
            ->table($failedTable . ' as fj')
            ->leftJoin('queue_monitors as qm', 'qm.job_id', '=', 'fj.uuid')
            ->whereNotNull('fj.uuid')
            ->whereNull('qm.id')
            ->count();
    }

    private function formatTimestampAgo(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return 'нет данных';
        }

        return Carbon::createFromTimestamp($timestamp)
            ->timezone(config('app.timezone'))
            ->diffForHumans();
    }
}
