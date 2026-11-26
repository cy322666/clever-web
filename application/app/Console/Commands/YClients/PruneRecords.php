<?php

namespace App\Console\Commands\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\RecordEvent;
use App\Models\Integrations\YClients\Setting;
use App\Services\YClients\RecordSyncLock;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PruneRecords extends Command
{
    protected $signature = 'yc:prune-records {--days=5} {--chunk=1000} {--dry-run}';

    protected $description = 'Delete old YClients records from local database by retention period in days';

    public function handle(): int
    {
        $days = max(1, (int)$this->option('days'));
        $chunk = min(1000, max(1, (int)$this->option('chunk')));
        $threshold = now()->subDays($days);
        if (!Schema::hasTable('yclients_record_events')) {
            $this->error('YClients event storage is required before pruning.');
            return self::FAILURE;
        }
        $stats = ['records' => 0, 'events' => 0, 'skipped' => 0];
        $dryRun = (bool) $this->option('dry-run');

        $this->candidates($threshold)->select('yclients_records.*')->chunkById($chunk, function ($records) use ($threshold, $dryRun, &$stats): void {
            // Read ready, delayed and reserved together so a worker moving a job cannot hide it.
            $queued = $this->queuedRecordIds();
            foreach ($records as $candidate) {
                if (isset($queued[$candidate->id])) {
                    $stats['skipped']++;
                    continue;
                }
                if ($dryRun) {
                    $stats['records']++;
                    $stats['events'] += RecordEvent::where('record_id', $candidate->id)->count();
                    continue;
                }
                $lock = RecordSyncLock::make((int) $candidate->id);
                if (!$lock->get()) {
                    $stats['skipped']++;
                    continue;
                }
                try {
                    DB::transaction(function () use ($candidate, $threshold, &$stats): void {
                        // Webhook intake locks the setting first. Keep the same lock order.
                        Setting::whereKey($candidate->setting_id)->lockForUpdate()->first();
                        $record = $this->candidates($threshold)->whereKey($candidate->id)->lockForUpdate()->first();
                        if (!$record) {
                            $stats['skipped']++;
                            return;
                        }
                        $events = RecordEvent::where('record_id', $record->id)->delete();
                        $deleted = Record::whereKey($record->id)->delete();
                        $stats['events'] += $events;
                        $stats['records'] += $deleted;
                    });
                } finally {
                    $lock->release();
                }
            }
        });

        $this->info(sprintf(
            'YClients prune %s. Records: %d. Events: %d. Skipped: %d. Threshold: before %s',
            $dryRun ? 'dry-run' : 'complete',
            $stats['records'],
            $stats['events'],
            $stats['skipped'],
            $threshold->toDateTimeString(),
        ));

        return self::SUCCESS;
    }

    private function candidates(DateTimeInterface $threshold): Builder
    {
        // Journal retention includes expired failures; the model's UI cleanup scope is success-only.
        return Record::query()->where('created_at', '<', $threshold)
            ->where(fn (Builder $query) => $query->whereNull('updated_at')->orWhere('updated_at', '<', $threshold))
            ->where(fn (Builder $query) => $query->whereNull('datetime')->orWhere('datetime', '<', $threshold))
            ->where(function (Builder $query): void {
                $query->whereIn('status', [Record::STATUS_SUCCESS, Record::STATUS_FAILED])
                    ->orWhere(function (Builder $query): void {
                        $query->where(fn (Builder $query) => $query->where('status', Record::STATUS_PENDING)->orWhereNull('status'))
                            ->whereNotExists(function ($query): void {
                                $query->selectRaw('1')->from('yclients_settings as s')
                                    ->whereColumn('s.id', 'yclients_records.setting_id')
                                    ->where('s.active', true);
                            });
                    });
            })
            ->whereNotExists(function ($query) use ($threshold): void {
                $query->selectRaw('1')->from('yclients_record_events as e')
                    ->whereColumn('e.record_id', 'yclients_records.id')
                    ->where(function ($query) use ($threshold): void {
                        $query->whereNull('e.status')->orWhereNotIn('e.status', [Record::STATUS_SUCCESS, Record::STATUS_FAILED])
                            ->orWhere('e.created_at', '>=', $threshold)
                            ->orWhere('e.updated_at', '>=', $threshold)
                            ->orWhere('e.dispatched_at', '>=', $threshold)
                            ->orWhere('e.processed_at', '>=', $threshold);
                    });
            });
    }

    private function queuedRecordIds(): array
    {
        $connection = config('queue.default');
        $driver = config("queue.connections.$connection.driver");
        $payloads = [];
        if ($driver === 'redis') {
            $redis = Redis::connection(config("queue.connections.$connection.connection", 'default'));
            $groups = $redis->eval(
                "return {redis.call('LRANGE', KEYS[1], 0, -1), redis.call('ZRANGE', KEYS[2], 0, -1), redis.call('ZRANGE', KEYS[3], 0, -1)}",
                3, 'queues:yclients_record', 'queues:yclients_record:delayed', 'queues:yclients_record:reserved',
            );
            $payloads = array_merge(...$groups);
        } elseif ($driver === 'database') {
            $payloads = DB::connection(config("queue.connections.$connection.connection"))
                ->table(config("queue.connections.$connection.table", 'jobs'))
                ->where('queue', 'yclients_record')->pluck('payload')->all();
        } elseif ($driver !== 'sync') {
            throw new RuntimeException('YClients pruning cannot inspect the configured queue driver.');
        }
        $ids = [];
        foreach ($payloads as $payload) {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (($data['displayName'] ?? null) !== \App\Jobs\YClients\RecordSend::class) {
                throw new RuntimeException('YClients pruning found an unrecognized queued job.');
            }
            $job = (array) @unserialize($data['data']['command'] ?? '', ['allowed_classes' => false]);
            $record = (array) ($job['record'] ?? []);
            $id = $record['id'] ?? null;
            if (!is_numeric($id) || (int) $id < 1) {
                throw new RuntimeException('YClients pruning could not identify a queued record.');
            }
            $ids[(int) $id] = true;
        }
        return $ids;
    }
}
