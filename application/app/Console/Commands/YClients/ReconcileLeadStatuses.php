<?php

namespace App\Console\Commands\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Setting;
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
        {--record-field-id=617051 : amoCRM lead field containing the YClients record id}
        {--company-field-id=617053 : amoCRM lead field containing the YClients company id}
        {--all-stages : Reconcile every lead in the selected pipeline}
        {--limit= : Max amoCRM leads to inspect}
        {--request-delay-ms=500 : Delay between YClients requests}
        {--only-issues : Print only deleted, mismatched and error cases}
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

        $statusMap = [
            (int)$waitStatus->status_id => 0,
            (int)$confirmStatus->status_id => 2,
        ];

        $this->line(sprintf(
            'Scanning amoCRM leads: pipelines=%s stages=%s mode=%s',
            implode(',', $pipelineIds),
            $this->option('all-stages') ? 'all' : implode(',', array_keys($statusMap)),
            $this->option('apply') ? 'apply' : 'dry-run',
        ));

        $amo = (new AmoClient($account))->init();
        $yc = new YClients($setting);
        $stats = [
            'fetched' => 0,
            'inspected' => 0,
            'matched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $seenLeadIds = [];
        $limit = $this->option('limit') !== null ? (int)$this->option('limit') : null;

        foreach ($pipelineIds as $pipelineId) {
            $sourceStatuses = $this->option('all-stages')
                ? [null]
                : array_keys($statusMap);

            foreach ($sourceStatuses as $sourceStatusId) {
                foreach ($this->amoLeads($amo, $pipelineId, $sourceStatusId) as $lead) {
                    $leadId = (string)data_get($lead, 'id');

                    if ($leadId === '' || isset($seenLeadIds[$leadId])) {
                        continue;
                    }
                    $seenLeadIds[$leadId] = true;
                    $stats['fetched']++;

                    if ($limit !== null && $stats['inspected'] >= $limit) {
                        break 3;
                    }

                    $stats['inspected']++;
                    $this->inspectLead($lead, $amo, $yc, $statusMap, $stats);
                }
            }
        }

        $this->info(sprintf(
            'Done. fetched=%d inspected=%d matched=%d updated=%d unchanged=%d skipped=%d failed=%d',
            $stats['fetched'],
            $stats['inspected'],
            $stats['matched'],
            $stats['updated'],
            $stats['unchanged'],
            $stats['skipped'],
            $stats['failed'],
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return iterable<int, array<string, mixed>> */
    private function amoLeads(AmoClient $amo, int $pipelineId, ?int $statusId): iterable
    {
        for ($page = 1; ; $page++) {
            $query = [
                'page' => $page,
                'limit' => 250,
                'filter[statuses][0][pipeline_id]' => $pipelineId,
            ];

            if ($statusId !== null) {
                $query['filter[statuses][0][status_id]'] = $statusId;
            }

            $response = $amo->requestV4('GET', '/api/v4/leads', [], $query);
            $leads = data_get($response, '_embedded.leads', []);

            if (!is_array($leads) || $leads === []) {
                break;
            }

            foreach ($leads as $lead) {
                if (is_array($lead)) {
                    yield $lead;
                }
            }

            if (count($leads) < 250) {
                break;
            }
        }
    }

    /** @param array<string, mixed> $lead @param array<int, int> $statusMap */
    private function inspectLead(
        array $lead,
        AmoClient $amo,
        YClients $yc,
        array $statusMap,
        array &$stats,
    ): void {
        $leadId = (string)data_get($lead, 'id');
        $currentStatusId = (int)data_get($lead, 'status_id');
        $recordId = $this->leadFieldValue($lead, (int)$this->option('record-field-id'));
        $companyId = $this->leadFieldValue($lead, (int)$this->option('company-field-id'));

        try {
            if ($recordId === null || $companyId === null) {
                $stats['skipped']++;
                $this->line($this->leadLine($lead, 'error-missing-yclients-ids', $recordId));

                return;
            }

            $response = $this->getYClientsRecord($yc, $companyId, $recordId);
            $recordData = data_get($response, 'data');

            if ($this->isYClientsRateLimited($response)) {
                $stats['failed']++;
                $this->line($this->leadLine($lead, 'error-yclients-rate-limit', $recordId) . ' company_id=' . $companyId);

                return;
            }

            if (data_get($recordData, 'deleted') === true || data_get($response, 'deleted') === true) {
                $stats['skipped']++;
                $this->line($this->leadLine($lead, 'deleted', $recordId) . ' company_id=' . $companyId);

                return;
            }

            if (!data_get($response, 'success') || !is_object($recordData)) {
                $stats['failed']++;
                $this->line($this->leadLine($lead, 'error-yclients-record-not-found', $recordId) . ' company_id=' . $companyId);

                return;
            }

            $attendance = (int)data_get($recordData, 'attendance', -999);

            if (!array_key_exists($currentStatusId, $statusMap)) {
                $stats['skipped']++;
                $this->line($this->leadLine($lead, 'skipped-unexpected-amo-stage', $recordId)
                    . ' attendance=' . $attendance);

                return;
            }

            if (!in_array($attendance, [0, 2], true)) {
                $stats['failed']++;
                $this->line($this->leadLine($lead, 'error-unexpected-attendance', $recordId)
                    . ' attendance=' . $attendance);

                return;
            }

            $stats['matched']++;
            $targetStatusId = array_search($attendance, $statusMap, true);

            if ($currentStatusId === $targetStatusId) {
                $stats['unchanged']++;

                if (!$this->option('only-issues')) {
                    $this->line($this->leadLine($lead, 'unchanged', $recordId)
                        . ' attendance=' . $attendance . ' company_id=' . $companyId);
                }

                return;
            }

            $this->line($this->leadLine($lead, $this->option('apply') ? 'updated' : 'would-update', $recordId)
                . sprintf(' status=%d->%d attendance=%d company_id=%s', $currentStatusId, $targetStatusId, $attendance, $companyId));

            if ($this->option('apply')) {
                $amo->requestV4('PATCH', '/api/v4/leads/' . (int)$leadId, [
                    'status_id' => (int)$targetStatusId,
                ]);
                $stats['updated']++;
            }
        } catch (Throwable $e) {
            $stats['failed']++;
            $this->line($this->leadLine($lead, 'error-exception', $recordId)
                . ' message=' . str_replace(["\r", "\n"], ' ', $e->getMessage()));
        }
    }

    private function getYClientsRecord(YClients $yc, string $companyId, string $recordId): ?object
    {
        $delayMs = max(0, (int)$this->option('request-delay-ms'));
        $retryDelays = [10, 20, 40, 60];
        $response = null;

        foreach (array_merge([0], $retryDelays) as $attempt => $retryDelay) {
            if ($retryDelay > 0) {
                sleep($retryDelay);
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            try {
                $response = $yc->getRecord($companyId, $recordId);
            } catch (Throwable $e) {
                if (!$this->isYClientsRateLimitException($e) || $attempt === count($retryDelays)) {
                    throw $e;
                }

                continue;
            }

            if (!$this->isYClientsRateLimited($response) || $attempt === count($retryDelays)) {
                return $response;
            }
        }

        return $response;
    }

    private function isYClientsRateLimitException(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, '429')
            || str_contains($message, 'лимит запросов')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'too many requests');
    }

    private function isYClientsRateLimited(?object $response): bool
    {
        $message = mb_strtolower((string)data_get($response, 'meta.message'));

        return str_contains($message, 'лимит запросов')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'too many requests');
    }

    private function resolveSetting(): Setting
    {
        $query = Setting::query()->where('user_id', (int)$this->argument('user_id'));

        if ($this->option('setting-id') !== null) {
            $query->whereKey((int)$this->option('setting-id'));
        }

        if ($this->option('account-id') !== null) {
            $query->where('account_id', (int)$this->option('account-id'));
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

    /** @param array<string, mixed> $lead */
    private function leadFieldValue(array $lead, int $fieldId): ?string
    {
        foreach ((array)data_get($lead, 'custom_fields_values', []) as $field) {
            if ((int)data_get($field, 'field_id') !== $fieldId) {
                continue;
            }

            $value = data_get($field, 'values.0.value');

            return filled($value) ? trim((string)$value) : null;
        }

        return null;
    }

    /** @param array<string, mixed> $lead */
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
