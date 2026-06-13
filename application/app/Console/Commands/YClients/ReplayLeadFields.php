<?php

namespace App\Console\Commands\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client as AmoClient;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\YClients\YClients;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;

class ReplayLeadFields extends Command
{
    protected $signature = 'yc:replay-lead-fields
        {user_id : Local user id}
        {--account-id= : Limit records by amoCRM account id}
        {--setting-id= : Limit records by YClients setting id}
        {--company-id= : Limit records by YClients company/branch id}
        {--record-id= : Limit to one YClients record id}
        {--order-by=created_at : Sort records by created_at, updated_at, datetime, or id}
        {--direction=asc : Sort direction: asc or desc}
        {--from-updated-at= : Process records updated at or after this date/time}
        {--to-updated-at= : Process records updated at or before this date/time}
        {--limit= : Max records to process}
        {--include-updated : Also process records already marked as successfully updated}
        {--dry-run : Show records without updating amoCRM}';

    protected $description = 'Replay YClients records chronologically and update all mapped amoCRM fields without moving leads.';

    private array $amoClients = [];

    private array $ycClients = [];

    public function handle(): int
    {
        $query = Record::query()
            ->where('user_id', $this->argument('user_id'))
            ->whereNotNull('lead_id')
            ->where('lead_id', '>', 0);

        $query->pendingMappedFieldsUpdate((bool)$this->option('include-updated'));

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

        foreach (['from-updated-at' => '>=', 'to-updated-at' => '<='] as $option => $operator) {
            if ($this->option($option) !== null) {
                $query->where('updated_at', $operator, Carbon::parse($this->option($option)));
            }
        }

        $orderBy = $this->orderByColumn();
        $direction = $this->orderDirection();

        $query->orderBy($orderBy, $direction);

        if ($orderBy !== 'id') {
            $query->orderBy('id', $direction);
        }

        $stats = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $this->info(
            sprintf(
                'Replaying YClients lead fields ordered by %s %s...',
                $orderBy,
                $direction
            )
        );

        foreach ($query->cursor() as $record) {
            $stats['processed']++;

            try {
                if ($record->isLeadOwnedByAnotherYClientsRecord()) {
                    $stats['skipped']++;
                    $this->markReplayResult($record, 'skipped_foreign_lead');
                    $this->line($this->recordLine($record, 'skipped-foreign-lead'));
                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line($this->recordLine($record, 'dry-run'));
                    $stats['skipped']++;
                    continue;
                }

                if ($this->replayRecord($record)) {
                    $stats['updated']++;
                    $this->markReplayResult($record, 'success');
                    $this->line($this->recordLine($record, 'updated'));
                    continue;
                }

                $stats['skipped']++;
                $this->markReplayResult($record, 'skipped');
                $this->line($this->recordLine($record, 'skipped'));
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->markReplayResult($record, 'failed', $e->getMessage());

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
            '[%s] record_db_id=%d record_id=%s company_id=%s lead_id=%s created_at=%s updated_at=%s',
            $status,
            $record->id,
            $record->record_id,
            $record->company_id,
            $record->lead_id,
            $record->created_at,
            $record->updated_at,
        );
    }

    private function markReplayResult(Record $record, string $status, ?string $error = null): void
    {
        Record::withoutTimestamps(fn() => $record->forceFill([
            'lead_fields_replay_status' => $status,
            'lead_fields_replayed_at' => now(),
            'lead_fields_replay_error' => $error,
            'mapped_fields_updated_at' => $status === 'success' ? now() : null,
            'mapped_fields_update_error' => $status === 'success' ? null : ($error ?: $status),
        ])->save());
    }

    private function orderByColumn(): string
    {
        $column = (string)$this->option('order-by');
        $allowed = ['created_at', 'updated_at', 'datetime', 'id'];

        if (!in_array($column, $allowed, true)) {
            $this->warn('Unsupported --order-by=' . $column . ', using created_at.');

            return 'created_at';
        }

        return $column;
    }

    private function orderDirection(): string
    {
        $direction = mb_strtolower((string)$this->option('direction'));

        if (!in_array($direction, ['asc', 'desc'], true)) {
            $this->warn('Unsupported --direction=' . $direction . ', using asc.');

            return 'asc';
        }

        return $direction;
    }

    /**
     * Updates only configured custom fields. This path never changes lead
     * status, pipeline, responsible user, or relations.
     */
    private function replayRecord(Record $record): bool
    {
        $setting = Setting::query()->find($record->setting_id);

        if (!$setting || (blank($setting->fields_lead) && blank($setting->fields_contact))) {
            return false;
        }

        $amoApi = $this->amoApi($record->account_id);
        $ycFields = Setting::YCGetFields($this->ycApi($setting), $record);
        $updated = false;

        if (!blank($setting->fields_contact)) {
            $contactId = $record->scopedClient()?->contact_id;

            if ($contactId) {
                $contact = Contacts::get($amoApi, $contactId);

                if ($contact) {
                    $this->updateContactFieldsWithRetry($setting, $amoApi, $contact, $ycFields, $record);
                    $updated = true;
                }
            }
        }

        if (!blank($setting->fields_lead)) {
            $lead = Leads::get($amoApi, $record->lead_id);

            if ($lead) {
                $this->updateLeadFieldsWithRetry($setting, $amoApi, $lead, $ycFields, $record);
                $updated = true;
            }
        }

        return $updated;
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

    /**
     * @throws Throwable
     */
    private function updateContactFieldsWithRetry(
        Setting $setting,
        AmoClient $amoApi,
        Contact $contact,
        array $ycFields,
        Record $record,
        int $maxAttempts = 5
    ): void {
        $currentContact = $contact;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $setting->YCSetContactFields($currentContact, $ycFields);

                return;
            } catch (Throwable $e) {
                if (!$this->isAmoLastModifiedConflict($e) || $attempt >= $maxAttempts) {
                    throw $e;
                }

                Log::warning('yc:replay-lead-fields amoCRM contact update conflict, retrying with fresh contact.', [
                    'record_db_id' => $record->id,
                    'record_id' => $record->record_id,
                    'contact_id' => $record->scopedClient()?->contact_id,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage(),
                ]);

                usleep(250000 * $attempt);
                $currentContact = Contacts::get($amoApi, $record->scopedClient()?->contact_id);

                if (!$currentContact) {
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
