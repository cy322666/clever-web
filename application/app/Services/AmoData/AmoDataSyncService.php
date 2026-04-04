<?php

namespace App\Services\AmoData;

use App\Jobs\AmoData\SyncReferences;
use App\Jobs\AmoData\SyncLeadPage;
use App\Jobs\AmoData\SyncTaskPage;
use App\Mail\AmoDataSyncFinished;
use App\Models\Integrations\AmoData\Setting;
use App\Models\Integrations\AmoData\SyncRun;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Account as AccountSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AmoDataSyncService
{
    private const INITIAL_SYNC_DAYS = 90;

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
        return $this->start($setting, 'initial', true);
    }

    /**
     * @throws \Exception
     */
    private function start(Setting $setting, string $type, bool $initial): SyncRun
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
            'meta' => [
                'refs_done' => false,
                'leads_done' => !data_get($setting->settings, 'sync_deals', true),
                'tasks_done' => !data_get($setting->settings, 'sync_tasks', true),
                'initial' => $initial,
                'type' => $type,
            ],
        ]);

        $setting->forceFill([
            'sync_status' => 'running',
            'last_attempt_at' => $startedAt,
            'last_error' => null,
        ])->save();

        try {
            $updatedFromLeads = $initial ? $this->initialSyncCutoff() : $setting->leads_synced_at;
            $updatedFromTasks = $initial ? $this->initialSyncCutoff() : $setting->tasks_synced_at;

            SyncReferences::dispatch($setting->id, $run->id);

            if (data_get($setting->settings, 'sync_deals', true)) {
                SyncLeadPage::dispatch($setting->id, $run->id, 1, $updatedFromLeads?->timestamp);
            }

            if (data_get($setting->settings, 'sync_tasks', true)) {
                SyncTaskPage::dispatch($setting->id, $run->id, 1, $updatedFromTasks?->timestamp);
            }

            return $run->fresh();
        } catch (Throwable $e) {
            $this->failRun($setting, $run, $e->getMessage());

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

    private function initialSyncCutoff(): Carbon
    {
        return now()->subDays(self::INITIAL_SYNC_DAYS);
    }

    /**
     * @throws \Exception
     */
    public function periodic(Setting $setting): SyncRun
    {
        return $this->start($setting, 'periodic', false);
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

    public function processLeadPage(Setting $setting, SyncRun $run, array $items, int $page, int $limit = 50): void
    {
        $result = $this->leadSync()->sync(
            $setting->user,
            $setting->user->account,
            $items,
            (bool)data_get($setting->settings, 'store_payloads', false),
        );

        $this->advanceRun($setting, $run, [
            'lead_items' => count($items),
            'leads_synced' => $result['synced'],
            'events_created' => $result['events'],
            'lead_page' => $page,
            'leads_done' => count($items) < $limit,
        ]);
    }

    public function processTaskPage(Setting $setting, SyncRun $run, array $items, int $page, int $limit = 50): void
    {
        $result = $this->taskSync()->sync(
            $setting->user,
            $setting->user->account,
            $items,
            (bool)data_get($setting->settings, 'store_payloads', false),
        );

        $this->advanceRun($setting, $run, [
            'task_items' => count($items),
            'tasks_synced' => $result['synced'],
            'events_created' => $result['events'],
            'task_page' => $page,
            'tasks_done' => count($items) < $limit,
        ]);
    }

    /**
     * @throws \Exception
     */
    public function processReferences(Setting $setting, SyncRun $run): void
    {
        $amoApi = new Client($setting->user->account);

        AccountSync::users($amoApi, $setting->user);
        AccountSync::statuses($amoApi, $setting->user);
        AccountSync::fields($amoApi, $setting->user);

        $this->advanceRun($setting, $run, [
            'refs_done' => true,
        ]);
    }

    public function completeIfReady(Setting $setting, SyncRun $run): void
    {
        $notify = false;

        DB::transaction(function () use ($setting, $run, &$notify) {
            /** @var SyncRun|null $lockedRun */
            $lockedRun = SyncRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedRun || $lockedRun->status !== 'running') {
                return;
            }

            $meta = $lockedRun->meta ?? [];

            if (!data_get($meta, 'refs_done', false)
                || !data_get($meta, 'leads_done', false)
                || !data_get($meta, 'tasks_done', false)) {
                return;
            }

            $finishedAt = now();

            $lockedRun->forceFill([
                'status' => 'success',
                'finished_at' => $finishedAt,
                'meta' => $meta,
            ])->save();

            $setting->forceFill([
                'sync_status' => 'success',
                'initial_synced_at' => $setting->initial_synced_at ?? $finishedAt,
                'last_successful_sync_at' => $finishedAt,
                'leads_synced_at' => data_get(
                    $setting->settings,
                    'sync_deals',
                    true
                ) ? $lockedRun->started_at : $setting->leads_synced_at,
                'tasks_synced_at' => data_get(
                    $setting->settings,
                    'sync_tasks',
                    true
                ) ? $lockedRun->started_at : $setting->tasks_synced_at,
                'last_leads_count' => $lockedRun->leads_synced,
                'last_tasks_count' => $lockedRun->tasks_synced,
                'last_events_count' => $lockedRun->events_created,
                'last_error' => null,
            ])->save();

            $notify = true;
        });

        if ($notify) {
            $this->sendCompletionMail($setting->fresh(), $run->fresh());
        }
    }

    public function failRun(Setting $setting, SyncRun $run, string $message): void
    {
        $notify = false;

        DB::transaction(function () use ($setting, $run, $message, &$notify) {
            $lockedRun = SyncRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if ($lockedRun && $lockedRun->status === 'running') {
                $lockedRun->forceFill([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error' => $message,
                ])->save();

                $notify = true;
            }

            $setting->forceFill([
                'sync_status' => 'failed',
                'last_error' => $message,
            ])->save();
        });

        if ($notify) {
            $this->sendCompletionMail($setting->fresh(), $run->fresh());
        }
    }

    private function advanceRun(Setting $setting, SyncRun $run, array $payload): void
    {
        DB::transaction(function () use ($setting, $run, $payload) {
            /** @var SyncRun|null $lockedRun */
            $lockedRun = SyncRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedRun || $lockedRun->status !== 'running') {
                return;
            }

            $meta = $lockedRun->meta ?? [];
            $meta['lead_items'] = (int)data_get($meta, 'lead_items', 0) + (int)data_get($payload, 'lead_items', 0);
            $meta['task_items'] = (int)data_get($meta, 'task_items', 0) + (int)data_get($payload, 'task_items', 0);

            if (array_key_exists('lead_page', $payload)) {
                $meta['lead_page'] = $payload['lead_page'];
            }

            if (array_key_exists('task_page', $payload)) {
                $meta['task_page'] = $payload['task_page'];
            }

            if (array_key_exists('leads_done', $payload)) {
                $meta['leads_done'] = (bool)$payload['leads_done'];
            }

            if (array_key_exists('tasks_done', $payload)) {
                $meta['tasks_done'] = (bool)$payload['tasks_done'];
            }

            if (array_key_exists('refs_done', $payload)) {
                $meta['refs_done'] = (bool)$payload['refs_done'];
            }

            $lockedRun->forceFill([
                'leads_synced' => $lockedRun->leads_synced + (int)data_get($payload, 'leads_synced', 0),
                'tasks_synced' => $lockedRun->tasks_synced + (int)data_get($payload, 'tasks_synced', 0),
                'events_created' => $lockedRun->events_created + (int)data_get($payload, 'events_created', 0),
                'meta' => $meta,
            ])->save();

            $setting->forceFill([
                'last_leads_count' => $lockedRun->leads_synced,
                'last_tasks_count' => $lockedRun->tasks_synced,
                'last_events_count' => $lockedRun->events_created,
                'last_error' => null,
            ])->save();
        });

        $this->completeIfReady($setting->fresh(), $run->fresh());
    }

    private function sendCompletionMail(Setting $setting, SyncRun $run): void
    {
        if (!$setting->user?->email) {
            return;
        }

        Mail::to($setting->user->email)->queue(new AmoDataSyncFinished($setting, $run));
    }
}
