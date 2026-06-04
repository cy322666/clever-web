<?php

namespace App\Console\Commands\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client as AmoClient;
use App\Services\amoCRM\Models\Leads;
use App\Services\YClients\YClients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use Ufee\Amo\Models\Lead;

class ReplayLeadFields extends Command
{
    protected $signature = 'yc:replay-lead-fields
        {user_id : Local user id}
        {--account-id= : Limit records by amoCRM account id}
        {--setting-id= : Limit records by YClients setting id}
        {--company-id= : Limit records by YClients company/branch id}
        {--record-id= : Limit to one YClients record id}
        {--limit= : Max records to process}
        {--dry-run : Show records without updating amoCRM}';

    protected $description = 'Replay YClients records chronologically and update only mapped amoCRM lead fields by existing lead_id.';

    private array $amoClients = [];

    private array $ycClients = [];

    public function handle(): int
    {
        $query = Record::query()
            ->where('user_id', $this->argument('user_id'))
            ->whereNotNull('lead_id')
            ->where('lead_id', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id');

        foreach (
            [
                'account-id' => 'account_id',
                'setting-id' => 'setting_id',
                'company-id' => 'company_id',
                'record-id' => 'record_id',
            ] as $option => $column
        ) {
            if ($this->option($option) !== null) {
                $query->where($column, $this->option($option));
            }
        }

        if ($this->option('limit') !== null) {
            $query->limit((int)$this->option('limit'));
        }

        $stats = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $this->info('Replaying YClients lead fields in chronological order...');

        foreach ($query->cursor() as $record) {
            $stats['processed']++;

            try {
                if ($this->option('dry-run')) {
                    $this->line($this->recordLine($record, 'dry-run'));
                    $stats['skipped']++;
                    continue;
                }

                if ($this->replayRecord($record)) {
                    $stats['updated']++;
                    $this->line($this->recordLine($record, 'updated'));
                    continue;
                }

                $stats['skipped']++;
                $this->line($this->recordLine($record, 'skipped'));
            } catch (Throwable $e) {
                $stats['failed']++;

                Log::error('yc:replay-lead-fields failed for record.', [
                    'record_db_id' => $record->id,
                    'record_id' => $record->record_id,
                    'lead_id' => $record->lead_id,
                    'account_id' => $record->account_id,
                    'setting_id' => $record->setting_id,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);

                $this->error($this->recordLine($record, 'failed') . ' error: ' . $e->getMessage());
            }
        }

        $this->info(
            sprintf(
                'Done. processed=%d updated=%d skipped=%d failed=%d',
                $stats['processed'],
                $stats['updated'],
                $stats['skipped'],
                $stats['failed'],
            )
        );

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function recordLine(Record $record, string $status): string
    {
        return sprintf(
            '[%s] record_db_id=%d record_id=%s company_id=%s lead_id=%s created_at=%s',
            $status,
            $record->id,
            $record->record_id,
            $record->company_id,
            $record->lead_id,
            $record->created_at,
        );
    }

    /**
     * Updates only amoCRM lead custom fields mapped in YClients settings.
     */
    private function replayRecord(Record $record): bool
    {
        $setting = Setting::query()->find($record->setting_id);

        if (!$setting || blank($setting->fields_lead)) {
            return false;
        }

        $amoApi = $this->amoApi($record->account_id);
        $lead = Leads::get($amoApi, $record->lead_id);

        if (!$lead) {
            return false;
        }

        $ycFields = Setting::YCGetFields($this->ycApi($setting), $record);
        $this->updateLeadFieldsWithRetry($setting, $amoApi, $lead, $ycFields, $record);

        return true;
    }

    private function amoApi(int $accountId): AmoClient
    {
        if (!isset($this->amoClients[$accountId])) {
            $account = Account::query()->findOrFail($accountId);
            $this->amoClients[$accountId] = (new AmoClient($account))->init();
        }

        return $this->amoClients[$accountId];
    }

    private function ycApi(Setting $setting): YClients
    {
        if (!isset($this->ycClients[$setting->id])) {
            $this->ycClients[$setting->id] = new YClients($setting);
        }

        return $this->ycClients[$setting->id];
    }

    /**
     * Keep this command safe under parallel amoCRM edits.
     *
     * @throws Throwable
     */
    private function updateLeadFieldsWithRetry(
        Setting $setting,
        AmoClient $amoApi,
        Lead $lead,
        array $ycFields,
        Record $record,
        int $maxAttempts = 5
    ): void {
        $currentLead = $lead;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $setting->YCSetLeadFields($currentLead, $ycFields);

                return;
            } catch (Throwable $e) {
                if (!$this->isAmoLastModifiedConflict($e) || $attempt >= $maxAttempts) {
                    throw $e;
                }

                Log::warning('yc:replay-lead-fields amoCRM update conflict, retrying with fresh lead.', [
                    'record_db_id' => $record->id,
                    'record_id' => $record->record_id,
                    'lead_id' => $record->lead_id,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage(),
                ]);

                usleep(250000 * $attempt);
                $currentLead = Leads::get($amoApi, $record->lead_id);

                if (!$currentLead) {
                    throw $e;
                }
            }
        }
    }

    private function isAmoLastModifiedConflict(Throwable $e): bool
    {
        return stripos($e->getMessage(), 'Last modified date is older than in') !== false;
    }
}
