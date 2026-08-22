<?php

namespace App\Console\Commands\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Models\amoCRM\Status;
use App\Services\amoCRM\Client as AmoClient;
use App\Services\amoCRM\Models\Leads as AmoLeads;
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
        {--record-field-id= : Deprecated compatibility option; local YClients links are used}
        {--all-stages : Reconcile every lead in the selected pipeline, not only the two mapped stages}
        {--limit= : Max leads to inspect}
        {--apply : Apply status changes; without this flag the command is a dry run}';

    protected $description = 'Reconcile amoCRM lead stages with current YClients record attendance.';

    public function handle(): int
    {
        $setting = $this->resolveSetting();
        $account = Account::query()->findOrFail($setting->account_id);
        $pipelineIds = $this->pipelineIds($setting);
        $waitStatus = $this->statusFor($setting->status_id_wait, 'status_id_wait');
        $confirmStatus = $this->statusFor($setting->status_id_confirm, 'status_id_confirm');

        if (!$pipelineIds) {
            $this->error('No pipelines configured for this YClients setting.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Scanning linked amoCRM leads: pipelines=%s mode=%s',
            implode(',', $pipelineIds),
            $this->option('apply') ? 'apply' : 'dry-run',
        ));

        $amo = (new AmoClient($account))->init();
        $yc = new YClients($setting);
        $records = Record::query()
            ->where('user_id', $setting->user_id)
            ->where('account_id', $account->id)
            ->where('setting_id', $setting->id)
            ->whereNotNull('lead_id')
            ->where('lead_id', '>', 0)
            ->orderByDesc('updated_at')
            ->cursor();
        $seenLeadIds = [];
        $stats = [
            'inspected' => 0,
            'matched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($records as $localRecord) {
            if (isset($seenLeadIds[$localRecord->lead_id])) {
                continue;
            }
            $seenLeadIds[$localRecord->lead_id] = true;

            try {
                $lead = AmoLeads::get($amo, $localRecord->lead_id);

                if (!$lead) {
                    $stats['skipped']++;
                    $this->line(sprintf('[skipped-lead-not-found] lead_id=%s record_id=%s', $localRecord->lead_id, $localRecord->record_id));
                    continue;
                }

                $leadData = $lead->toArray();

                if (!$this->leadBelongsToPipelines($leadData, $pipelineIds)) {
                    continue;
                }

                $currentStatusId = (int)data_get($leadData, 'status_id');
                $mappedStatusIds = [(int)$waitStatus->status_id, (int)$confirmStatus->status_id];

                if (!$this->option('all-stages') && !in_array($currentStatusId, $mappedStatusIds, true)) {
                    $stats['skipped']++;
                    continue;
                }

                if ($this->option('limit') !== null && $stats['inspected'] >= (int)$this->option('limit')) {
                    break;
                }

                $stats['inspected']++;

                $recordId = (string)$localRecord->record_id;

                if (!$localRecord->company_id) {
                    $stats['skipped']++;
                    $this->line($this->leadLine($leadData, 'skipped-no-company', $recordId));
                    continue;
                }

                $response = $yc->getRecord((string)$localRecord->company_id, (string)$recordId);
                $recordData = data_get($response, 'data');

                if (!data_get($response, 'success') || !is_object($recordData)) {
                    $stats['skipped']++;
                    $this->line($this->leadLine($leadData, 'skipped-yclients-record-not-found', $recordId));
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
                    $this->line($this->leadLine($leadData, 'skipped-attendance-' . $attendance, $recordId));
                    continue;
                }

                $stats['matched']++;
                $targetStatusId = (int)$targetStatus->status_id;

                if ($currentStatusId === $targetStatusId) {
                    $stats['unchanged']++;
                    $this->line($this->leadLine($leadData, 'unchanged', $recordId) . ' attendance=' . $attendance);
                    continue;
                }

                $this->line($this->leadLine($leadData, $this->option('apply') ? 'updated' : 'would-update', $recordId)
                    . sprintf(' status=%d->%d attendance=%d', $currentStatusId, $targetStatusId, $attendance));

                if ($this->option('apply')) {
                    $amo->requestV4('PATCH', '/api/v4/leads/' . (int)data_get($lead, 'id'), [
                        'status_id' => $targetStatusId,
                    ]);
                    $stats['updated']++;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->error(sprintf('[failed] lead_id=%s record_id=%s error=%s', $localRecord->lead_id, $localRecord->record_id, $e->getMessage()));
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
