<?php

namespace App\Services\AmoData;

use App\Models\Integrations\AmoData\Setting;
use App\Models\Integrations\AmoData\SyncRun;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Account as AccountSync;
use Illuminate\Support\Carbon;
use Throwable;

class AmoDataSyncService
{
    public function __construct(
        private readonly ?LeadSyncService $leadSyncService = null,
        private readonly ?TaskSyncService $taskSyncService = null,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function initial(Setting $setting): SyncRun
    {
        return $this->sync($setting, 'initial', true);
    }

    /**
     * @throws \Exception
     */
    private function sync(Setting $setting, string $type, bool $initial): SyncRun
    {
        $user = $setting->user;
        $account = $user->account;
        $startedAt = now();

        $run = $setting->runs()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => $type,
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        $setting->forceFill([
            'sync_status' => 'running',
            'last_attempt_at' => $startedAt,
            'last_error' => null,
        ])->save();

        try {
            $amoApi = new Client($account);

            AccountSync::users($amoApi, $user);
            AccountSync::statuses($amoApi, $user);
            AccountSync::fields($amoApi, $user);

            $api = new AmoApiService($amoApi);

            $leadItems = data_get($setting->settings, 'sync_deals', true)
                ? $api->getLeads($initial ? null : $setting->leads_synced_at)
                : [];

            $taskItems = data_get($setting->settings, 'sync_tasks', true)
                ? $api->getTasks($initial ? null : $setting->tasks_synced_at)
                : [];

            $leadResult = $this->leadSync()->sync($user, $account, $leadItems);
            $taskResult = $this->taskSync()->sync($user, $account, $taskItems);

            $finishedAt = now();

            $run->forceFill([
                'status' => 'success',
                'finished_at' => $finishedAt,
                'leads_synced' => $leadResult['synced'],
                'tasks_synced' => $taskResult['synced'],
                'events_created' => $leadResult['events'] + $taskResult['events'],
                'meta' => [
                    'lead_items' => count($leadItems),
                    'task_items' => count($taskItems),
                ],
            ])->save();

            $setting->forceFill([
                'sync_status' => 'success',
                'initial_synced_at' => $setting->initial_synced_at ?? $finishedAt,
                'last_successful_sync_at' => $finishedAt,
                'leads_synced_at' => data_get(
                    $setting->settings,
                    'sync_deals',
                    true
                ) ? $startedAt : $setting->leads_synced_at,
                'tasks_synced_at' => data_get(
                    $setting->settings,
                    'sync_tasks',
                    true
                ) ? $startedAt : $setting->tasks_synced_at,
                'last_leads_count' => $leadResult['synced'],
                'last_tasks_count' => $taskResult['synced'],
                'last_events_count' => $leadResult['events'] + $taskResult['events'],
                'last_error' => null,
            ])->save();

            return $run->fresh();
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $e->getMessage(),
            ])->save();

            $setting->forceFill([
                'sync_status' => 'failed',
                'last_error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }

    private function leadSync(): LeadSyncService
    {
        return $this->leadSyncService ?? new LeadSyncService();
    }

    private function taskSync(): TaskSyncService
    {
        return $this->taskSyncService ?? new TaskSyncService();
    }

    /**
     * @throws \Exception
     */
    public function periodic(Setting $setting): SyncRun
    {
        return $this->sync($setting, 'periodic', false);
    }

    public function isDue(Setting $setting): bool
    {
        if (!$setting->active) {
            return false;
        }

        if (!$setting->last_successful_sync_at) {
            return true;
        }

        return $setting->last_successful_sync_at
            ->copy()
            ->addMinutes($setting->syncIntervalMinutes())
            ->isPast();
    }
}
