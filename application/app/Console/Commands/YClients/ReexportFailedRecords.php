<?php

namespace App\Console\Commands\YClients;

use App\Jobs\YClients\RecordSend;
use App\Models\Integrations\YClients\Record;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReexportFailedRecords extends Command
{
    protected $signature = 'yc:reexport-failed
        {user_id : Local user id}
        {--from= : Start of period}
        {--to= : End of period}
        {--date-column=created_at : Period column: created_at, updated_at, datetime}
        {--account-id= : Limit records by amoCRM account id}
        {--setting-id= : Limit records by YClients setting id}
        {--company-id= : Limit records by YClients company/branch id}
        {--record-db-id= : Limit to one local yclients_records id}
        {--record-id= : Limit to one YClients record id}
        {--limit= : Max records to re-export}
        {--include-pending : Also re-export non-success records without an error message}
        {--sync : Run normal flow immediately instead of dispatching queue jobs}
        {--with-notes : Allow note creation during re-export}
        {--dry-run : Show records without updating status or dispatching jobs}';

    protected $description = 'Re-export failed YClients records through the normal integration flow for a selected period.';

    public function handle(): int
    {
        if ($this->option('from') === null && $this->option('to') === null) {
            $this->error('Укажи период через --from и/или --to, чтобы случайно не перевыгрузить все ошибки.');

            return self::FAILURE;
        }

        $dateColumn = $this->dateColumn();
        $query = Record::query()
            ->with(['account', 'setting'])
            ->where('user_id', $this->argument('user_id'))
            ->failedExport((bool)$this->option('include-pending'));

        foreach (
            [
                'account-id' => 'account_id',
                'setting-id' => 'setting_id',
                'company-id' => 'company_id',
                'record-db-id' => 'id',
                'record-id' => 'record_id',
            ] as $option => $column
        ) {
            if ($this->option($option) !== null) {
                $query->where($column, $this->option($option));
            }
        }

        if ($this->option('from') !== null) {
            $query->where($dateColumn, '>=', Carbon::parse($this->option('from')));
        }

        if ($this->option('to') !== null) {
            $query->where($dateColumn, '<=', Carbon::parse($this->option('to')));
        }

        $query->orderBy($dateColumn)->orderBy('id');

        if ($this->option('limit') !== null) {
            $query->limit(max(1, (int)$this->option('limit')));
        }

        $stats = [
            'processed' => 0,
            'queued' => 0,
            'synced' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $this->info(
            sprintf(
                'Re-exporting failed YClients records by %s from=%s to=%s mode=%s notes=%s include_pending=%s',
                $dateColumn,
                $this->option('from') ?? '-',
                $this->option('to') ?? '-',
                $this->option('sync') ? 'sync' : 'queue',
                $this->option('with-notes') ? 'enabled' : 'disabled',
                $this->option('include-pending') ? 'yes' : 'no',
            )
        );

        foreach ($query->cursor() as $record) {
            $stats['processed']++;

            if ($this->option('dry-run')) {
                $stats['skipped']++;
                $this->line($this->recordLine($record, 'dry-run'));
                continue;
            }

            if (!$record->account || !$record->setting) {
                $stats['failed']++;
                $this->markFailed($record, 'YClients re-export skipped: account or setting not found.');
                $this->error($this->recordLine($record, 'failed-missing-account-or-setting'));
                continue;
            }

            try {
                $this->markPending($record);

                if ($this->option('sync')) {
                    $this->runNormalFlowNow($record);
                    $stats['synced']++;
                    $this->line($this->recordLine($record->fresh(), 'synced'));
                    continue;
                }

                RecordSend::dispatch(
                    $record->fresh(),
                    $record->account,
                    $record->setting,
                    (bool)$this->option('with-notes'),
                );

                $stats['queued']++;
                $this->line($this->recordLine($record, 'queued'));
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->markFailed($record, 'YClients re-export failed before dispatch.', [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);

                Log::error('yc:reexport-failed failed for record.', [
                    'record_db_id' => $record->id,
                    'record_id' => $record->record_id,
                    'company_id' => $record->company_id,
                    'account_id' => $record->account_id,
                    'setting_id' => $record->setting_id,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);

                $this->error($this->recordLine($record, 'failed') . ' error: ' . $e->getMessage());
            }
        }

        if ($stats['processed'] === 0) {
            $this->warn(
                'No records matched. Check USER_ID, period, status, and whether you used --record-id (YClients id) or --record-db-id (local table id).'
            );
        }

        $this->info(
            sprintf(
                'Done. processed=%d queued=%d synced=%d skipped=%d failed=%d',
                $stats['processed'],
                $stats['queued'],
                $stats['synced'],
                $stats['skipped'],
                $stats['failed'],
            )
        );

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function dateColumn(): string
    {
        $column = (string)$this->option('date-column');
        $allowed = ['created_at', 'updated_at', 'datetime'];

        if (!in_array($column, $allowed, true)) {
            $this->warn('Unsupported --date-column=' . $column . ', using created_at.');

            return 'created_at';
        }

        return $column;
    }

    private function recordLine(Record $record, string $status): string
    {
        return sprintf(
            '[%s] record_db_id=%d record_id=%s company_id=%s lead_id=%s status=%s created_at=%s updated_at=%s datetime=%s error=%s',
            $status,
            $record->id,
            $record->record_id,
            $record->company_id,
            $record->lead_id ?? '-',
            $record->status ?? '-',
            $record->created_at ?? '-',
            $record->updated_at ?? '-',
            $record->datetime ?? '-',
            $record->error_message ? str_replace(["\r", "\n"], ' | ', $record->error_message) : '-',
        );
    }

    private function markFailed(Record $record, string $message, array $context = []): void
    {
        $record->forceFill([
            'status' => Record::STATUS_FAILED,
            'error_message' => $this->formatErrorMessage($message, $context),
        ])->save();
    }

    private function formatErrorMessage(string $message, array $context = []): string
    {
        $lines = [$message];

        foreach ($context as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $lines[] = $key . ': ' . $value;
        }

        return implode("\n", $lines);
    }

    private function markPending(Record $record): void
    {
        $record->forceFill([
            'status' => Record::STATUS_PENDING,
            'error_message' => null,
        ])->save();
    }

    private function runNormalFlowNow(Record $record): void
    {
        $exitCode = Artisan::call('yc:send-record', [
            'record_id' => $record->id,
            'account_id' => $record->account_id,
            'setting_id' => $record->setting_id,
            '--skip-note' => !$this->option('with-notes'),
        ]);

        if ($exitCode !== 0) {
            throw new \RuntimeException('yc:send-record failed: ' . trim(Artisan::output()));
        }

        $exitCode = Artisan::call('yc:update-entities', [
            'record_id' => $record->id,
            'account_id' => $record->account_id,
            'setting_id' => $record->setting_id,
        ]);

        if ($exitCode !== 0) {
            throw new \RuntimeException('yc:update-entities failed: ' . trim(Artisan::output()));
        }
    }
}
