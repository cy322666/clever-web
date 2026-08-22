<?php

namespace App\Console\Commands\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Models\amoCRM\Field;
use App\Models\amoCRM\Status;
use App\Services\amoCRM\Client as AmoClient;
use App\Services\YClients\YClients;
use Illuminate\Console\Command;
use Throwable;

class ReconcileLeadStatuses extends Command
{
    protected $signature = 'yc:reconcile-lead-statuses
        {user_id : Local user id}
        {--account-id= : Limit to amoCRM account id}
        {--setting-id= : Limit to YClients setting id}
        {--pipeline-id= : Override the pipeline id to scan}
        {--record-field-id= : Override amoCRM lead field id for YClients record id}
        {--all-stages : Reconcile every lead in the selected pipeline, not only the two mapped stages}
        {--limit= : Max leads to inspect}
        {--apply : Apply status changes; without this flag the command is a dry run}';

    protected $description = 'Reconcile amoCRM lead stages with current YClients record attendance.';

    public function handle(): int
    {
        $setting = $this->resolveSetting();
        $account = Account::query()->findOrFail($setting->account_id);
        $pipelineIds = $this->pipelineIds($setting);
        $recordFieldId = $this->recordFieldId($setting);
        $waitStatus = $this->statusFor($setting->status_id_wait, 'status_id_wait');
        $confirmStatus = $this->statusFor($setting->status_id_confirm, 'status_id_confirm');

        if (!$pipelineIds) {
            $this->error('No pipelines configured for this YClients setting.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Scanning amoCRM leads: pipelines=%s record_field_id=%d mode=%s',
            implode(',', $pipelineIds),
            $recordFieldId,
            $this->option('apply') ? 'apply' : 'dry-run',
        ));

        $amo = (new AmoClient($account))->init();
        $yc = new YClients($setting);
        $stats = [
            'inspected' => 0,
            'matched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($this->leadPages($amo) as $lead) {
            if (!$this->leadBelongsToPipelines($lead, $pipelineIds)) {
                continue;
            }

            try {
                $currentStatusId = (int)data_get($lead, 'status_id');
                $mappedStatusIds = [(int)$waitStatus->status_id, (int)$confirmStatus->status_id];

                if (!$this->option('all-stages') && !in_array($currentStatusId, $mappedStatusIds, true)) {
                    $stats['skipped']++;
                    continue;
                }

                $recordId = $this->leadFieldValue($lead, $recordFieldId);

                if (!$recordId) {
                    $stats['skipped']++;
                    $this->line($this->leadLine($lead, 'skipped-no-record-id'));
                    continue;
                }

                if ($this->option('limit') !== null && $stats['inspected'] >= (int)$this->option('limit')) {
                    break;
                }

                $stats['inspected']++;

                $localRecord = Record::query()
                    ->where('setting_id', $setting->id)
                    ->where('account_id', $account->id)
                    ->where('record_id', $recordId)
                    ->orderByDesc('updated_at')
                    ->first();

                if (!$localRecord || !$localRecord->company_id) {
                    $stats['skipped']++;
                    $this->line($this->leadLine($lead, 'skipped-no-local-record', $recordId));
                    continue;
                }

                $response = $yc->getRecord((string)$localRecord->company_id, (string)$recordId);
                $recordData = data_get($response, 'data');

                if (!data_get($response, 'success') || !is_object($recordData)) {
                    $stats['skipped']++;
                    $this->line($this->leadLine($lead, 'skipped-yclients-record-not-found', $recordId));
                    continue;
                }

                $attendance = (int)data_get($recordData, 'attendance', -999);
                $targetStatus = match ($attendance) {
                    0 => $waitStatus,
                    2 => $confirmStatus,
                    default => null,
                };

                if (!$targetStatus) {
                    $stats['skipped']++;
                    $this->line($this->leadLine($lead, 'skipped-attendance-' . $attendance, $recordId));
                    continue;
                }

                $stats['matched']++;
                $targetStatusId = (int)$targetStatus->status_id;

                if ($currentStatusId === $targetStatusId) {
                    $stats['unchanged']++;
                    $this->line($this->leadLine($lead, 'unchanged', $recordId) . ' attendance=' . $attendance);
                    continue;
                }

                $this->line($this->leadLine($lead, $this->option('apply') ? 'updated' : 'would-update', $recordId)
                    . sprintf(' status=%d->%d attendance=%d', $currentStatusId, $targetStatusId, $attendance));

                if ($this->option('apply')) {
                    $amo->requestV4('PATCH', '/api/v4/leads/' . (int)data_get($lead, 'id'), [
                        'status_id' => $targetStatusId,
                    ]);
                    $stats['updated']++;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->error($this->leadLine($lead, 'failed') . ' error=' . $e->getMessage());
            }
        }

        $this->info(sprintf(
            'Done. inspected=%d matched=%d updated=%d unchanged=%d skipped=%d failed=%d',
            $stats['inspected'],
            $stats['matched'],
            $stats['updated'],
            $stats['unchanged'],
            $stats['skipped'],
            $stats['failed'],
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveSetting(): Setting
    {
        $query = Setting::query()->where('user_id', (int)$this->argument('user_id'));

        if ($this->option('setting-id') !== null) {
            $query->whereKey((int)$this->option('setting-id'));
        }

        return $query->firstOrFail();
    }

    /** @return array<int, int> */
    private function pipelineIds(Setting $setting): array
    {
        if ($this->option('pipeline-id') !== null) {
            return [(int)$this->option('pipeline-id')];
        }

        $configured = collect((array)$setting->pipelines)
            ->map(fn($id): int => (int)$id)
            ->filter()
            ->values()
            ->all();

        if ($configured) {
            return $configured;
        }

        return Status::query()
            ->where('user_id', $setting->user_id)
            ->where('is_main', true)
            ->where('active', true)
            ->pluck('pipeline_id')
            ->map(fn($id): int => (int)$id)
            ->unique()
            ->values()
            ->all();
    }

    private function recordFieldId(Setting $setting): int
    {
        if ($this->option('record-field-id') !== null) {
            return (int)$this->option('record-field-id');
        }

        $fieldId = Field::query()
            ->where('user_id', $setting->user_id)
            ->where('entity_type', 'leads')
            ->where('active', true)
            ->where(function ($query): void {
                $query->where('name', 'ID записи')->orWhere('code', 'ID_RECORD');
            })
            ->value('field_id');

        if (!$fieldId) {
            throw new \RuntimeException('amoCRM lead field "ID записи" was not found. Use --record-field-id.');
        }

        return (int)$fieldId;
    }

    private function statusFor(?string $value, string $name): object
    {
        $status = Status::getObject($value);

        if (empty($value) || empty($status->status_id) || empty($status->pipeline_id)) {
            throw new \RuntimeException('YClients setting has no valid ' . $name . ' mapping.');
        }

        return $status;
    }

    private function leadBelongsToPipelines(array $lead, array $pipelineIds): bool
    {
        return in_array((int)data_get($lead, 'pipeline_id'), $pipelineIds, true);
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function leadPages(AmoClient $amo): \Generator
    {
        $page = 1;
        $pageSize = 250;

        do {
            $response = $amo->requestV4('GET', '/api/v4/leads', [], [
                'page' => $page,
                'limit' => $pageSize,
            ]);
            $items = data_get($response, '_embedded.leads', []);

            foreach ($items as $lead) {
                yield (array)$lead;
            }

            $count = count($items);
            $page++;
        } while ($count === $pageSize);
    }

    private function leadFieldValue(array $lead, int $fieldId): ?string
    {
        $value = null;

        foreach ((array)data_get($lead, 'custom_fields_values', []) as $field) {
            if ((int)data_get($field, 'field_id') !== $fieldId) {
                continue;
            }

            $value = data_get((array)data_get($field, 'values', []), '0.value');
            break;
        }

        if ($value === null || trim((string)$value) === '') {
            return null;
        }

        return (string)(int)trim((string)$value);
    }

    private function leadLine(array $lead, string $state, ?string $recordId = null): string
    {
        return sprintf(
            '[%s] lead_id=%s record_id=%s pipeline_id=%s status_id=%s',
            $state,
            data_get($lead, 'id'),
            $recordId ?: '-',
            data_get($lead, 'pipeline_id'),
            data_get($lead, 'status_id'),
        );
    }
}
