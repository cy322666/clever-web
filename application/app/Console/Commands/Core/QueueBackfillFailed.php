<?php

namespace App\Console\Commands\Core;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueBackfillFailed extends Command
{
    protected $signature = 'app:queue-backfill-failed {--limit=5000} {--dry-run}';

    protected $description = 'Backfill failed_jobs into queue_monitors for Filament queue monitor UI';

    public function handle(): int
    {
        $limit = max(1, (int)$this->option('limit'));
        $dryRun = (bool)$this->option('dry-run');
        $startedAt = now()->timestamp;

        $failedConnection = (string)(config('queue.failed.database') ?? config('database.default'));
        $monitorConnection = (string)(config('filament-jobs-monitor.connection') ?? config('database.default'));
        $failedTable = (string)config('queue.failed.table', 'failed_jobs');

        if (!Schema::connection($failedConnection)->hasTable($failedTable)) {
            $this->error("Table {$failedTable} not found on connection {$failedConnection}");
            $this->storeTelemetry($startedAt, 0, 0);

            return self::FAILURE;
        }

        if (!Schema::connection($monitorConnection)->hasTable('queue_monitors')) {
            $this->error("Table queue_monitors not found on connection {$monitorConnection}");
            $this->storeTelemetry($startedAt, 0, 0);

            return self::FAILURE;
        }

        $failedRows = DB::connection($failedConnection)
            ->table($failedTable)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($failedRows->isEmpty()) {
            $this->info('No failed jobs found for backfill.');
            $this->storeTelemetry($startedAt, 0, 0);

            return self::SUCCESS;
        }

        $jobIds = $failedRows
            ->map(fn(object $row): string => (string)($row->uuid ?? $row->id ?? ''))
            ->filter()
            ->unique()
            ->values();

        $existing = DB::connection($monitorConnection)
            ->table('queue_monitors')
            ->whereIn('job_id', $jobIds)
            ->pluck('job_id')
            ->map(fn($id): string => (string)$id)
            ->all();

        $existingMap = array_fill_keys($existing, true);

        $insertRows = [];

        foreach ($failedRows as $row) {
            $jobId = (string)($row->uuid ?? $row->id ?? '');

            if ($jobId === '' || isset($existingMap[$jobId])) {
                continue;
            }

            $payload = [];

            if (is_string($row->payload ?? null)) {
                $decoded = json_decode($row->payload, true);

                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            $failedAt = filled($row->failed_at ?? null)
                ? Carbon::parse($row->failed_at)
                : now();

            $insertRows[] = [
                'job_id' => $jobId,
                'name' => (string)($payload['displayName'] ?? $payload['job'] ?? ($row->queue ?? 'Unknown Job')),
                'queue' => (string)($row->queue ?? ($payload['queue'] ?? 'default')),
                'started_at' => $failedAt,
                'finished_at' => $failedAt,
                'failed' => true,
                'attempt' => max(1, (int)($payload['attempts'] ?? 1)),
                'progress' => 100,
                'exception_message' => is_string($row->exception ?? null)
                    ? mb_strcut($row->exception, 0, 65535)
                    : null,
                'tenant_id' => null,
                'created_at' => $failedAt,
                'updated_at' => now(),
            ];
        }

        $this->info('Failed rows scanned: ' . $failedRows->count());
        $this->info('Rows to backfill: ' . count($insertRows));

        if ($dryRun || count($insertRows) === 0) {
            $this->line($dryRun ? 'Dry-run mode: nothing was written.' : 'Nothing to write.');
            $this->storeTelemetry($startedAt, $failedRows->count(), 0);

            return self::SUCCESS;
        }

        foreach (array_chunk($insertRows, 500) as $chunk) {
            DB::connection($monitorConnection)
                ->table('queue_monitors')
                ->insert($chunk);
        }

        $this->info('Backfill completed. Inserted: ' . count($insertRows));
        $this->storeTelemetry($startedAt, $failedRows->count(), count($insertRows));

        return self::SUCCESS;
    }

    private function storeTelemetry(int $startedAt, int $scanned, int $inserted): void
    {
        Cache::forever('monitoring:queue:backfill:last_run', $startedAt);
        Cache::forever('monitoring:queue:backfill:last_scanned', $scanned);
        Cache::forever('monitoring:queue:backfill:last_inserted', $inserted);
    }
}
