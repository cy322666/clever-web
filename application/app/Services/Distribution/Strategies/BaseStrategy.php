<?php

namespace App\Services\Distribution\Strategies;

use App\Models\amoCRM\Staff;
use App\Models\Integrations\Distribution\Setting;
use App\Models\Integrations\Distribution\Transaction;
use App\Models\User;
use App\Services\Distribution\ScheduleEvaluator;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Leads;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ufee\Amo\Base\Services\Model;
use Ufee\Amo\Models\Lead;

class BaseStrategy
{
    public User $user;
    public Setting $setting;
    public Transaction $transaction;

    public ?array $template = [];
    public ?int $templateIndex = null;
    public ?string $queueUuid = null;
    public ?array $staffs = [];

    //те по кому распределяем после проверок
    public ?array $activeStaff = [];

    public Collection|array $transactions;

    /**
     * @param User $user
     * @param Transaction $transaction
     * @param Setting $setting
     * @return $this
     */
    public function setModels(User $user, Transaction $transaction, Setting $setting): static
    {
        $this->user = $user;
        $this->setting = $setting;
        $this->transaction = $transaction;

        $settings = json_decode($this->setting->settings ?? '[]', true);
        $settings = is_array($settings) ? $settings : [];
        [$this->template, $this->templateIndex, $this->queueUuid] = $this->resolveTemplate($settings);

        $this->type = $this->transaction->type;
        $this->staffs = $this->template['staffs'] ?? [];

        return $this;
    }

    public function setTransactions(?Carbon $dateAt = null): static
    {
        $dateAt = $dateAt !== null ? $dateAt : Carbon::now()->timezone($this->resolveTimezone());

        $query = Transaction::query()
            ->where('created_at', '>', $dateAt->format('Y-m-d').' 00:00:00')
            ->where('user_id', $this->transaction->user_id)
            ->where('status', true)
            ->where('id', '<', $this->transaction->id)
            ->when(
                !empty($this->queueUuid),
                fn($query) => $query->where('queue_uuid', $this->queueUuid),
                fn($query) => $query->when(
                    $this->templateIndex !== null,
                    fn($inner) => $inner->where('template', $this->templateIndex)
                )
            )
            ->orderBy('id', 'DESC');

        $this->transactions = $query->get();

        Log::debug(__METHOD__.' user_id '.$this->user->id, ['query' => $query->toRawSql()]);

        return $this;
    }

    public function sliceSchedule()
    {
        $this->activeStaff = array_values($this->staffs ?? []);

        //по графику?
        if ($this->transaction->schedule) {

            Log::debug(__METHOD__.' ДО отбора по рабочему графику  : ', [$this->staffs]);

            $now = Carbon::now()->timezone($this->resolveTimezone());
            $this->activeStaff = [];

            //отбираем только тех, кто работает по графику сейчас
            foreach ($this->staffs as $staffId) {
                $staff = Staff::query()->where('staff_id', $staffId)->first();
                if (!$staff) {
                    continue;
                }

                if (ScheduleEvaluator::isWorkingNow(
                    $staff->schedule->settings ?? null,
                    $now,
                    $this->resolveTimezone()
                )) {
                    Log::info(__METHOD__ . ' staff_id => ' . $staff->id . ' is work');
                    $this->activeStaff[] = $staff->staff_id;
                }
            }

            Log::debug(__METHOD__.' ПОСЛЕ отбора по рабочему графику остались : ', $this->activeStaff);
        }

        return $this;
    }

    /**
     * @param Client $amoApi
     * @param int $staff
     * @throws \Exception
     */
    public function changeResponsible(Client $amoApi, int $staff)
    {
        $lead = $amoApi->service
            ->leads()
            ->find($this->transaction->lead_id);

        if ($lead) {

            $lead->responsible_user_id = $staff;
            $lead->save();

            if (!empty($this->template['update_contact_company']) &&
                $this->template['update_contact_company'] == 'yes') {

                $contact = $lead->contact ?? null;

                if ($contact) {
                    $contact->responsible_user_id = $staff;
                    $contact->save();
                }

                $company = $lead->company ?? null;

                if ($company) {
                    $company->responsible_user_id = $staff;
                    $company->save();
                }
            }

            if (!empty($this->template['update_tasks']) &&
                $this->template['update_tasks'] == 'yes') {

                $tasks = $lead->tasks;

                foreach ($tasks as $task) {

                    $task->responsible_user_id = $staff;
                    $task->save();
                }
            }
        }

        return $lead;
    }

    //проверяет открытые сделки
    public function checkActiveGetStaff(Client $amoApi, Transaction $transaction) : bool|int
    {
        $contactId = $transaction->contact_id;

        if (!$contactId) {
            $lead = $amoApi->service->leads()->find($transaction->lead_id);
            $contactId = $lead->contact->id ?? null;
        }

        if (!$contactId) {
            return false;
        }

        if (($this->template['check_active'] ?? 'no') !== 'yes') {
            return false;
        }

        $contact = $amoApi->service->contacts()->find($contactId);
        if (!$contact) {
            return false;
        }

        $leads = Leads::searchActiveLeads($contact);
        if (count($leads) <= 1) {
            return false;
        }

        foreach ($leads as $lead) {
            if ($lead->id === $transaction->lead_id) {
                continue;
            }

            if (in_array($lead->responsible_user_id, $this->staffs, true)) {
                return (int)$lead->responsible_user_id;
            }
        }

        return false;
    }

    protected function pickNextStaffByCursor(array $staffs): ?int
    {
        $staffs = array_values(array_unique(array_map('intval', $staffs)));
        if (count($staffs) === 0) {
            return null;
        }

        $cursorKey = $this->queueUuid ?: ('legacy:' . ($this->templateIndex ?? $this->transaction->template ?? '0'));

        return DB::transaction(function () use ($cursorKey, $staffs): int {
            /** @var Setting|null $lockedSetting */
            $lockedSetting = Setting::query()
                ->whereKey($this->setting->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedSetting) {
                return $staffs[0];
            }

            $cursors = json_decode($lockedSetting->cursors ?? '[]', true);
            $cursors = is_array($cursors) ? $cursors : [];

            $lastStaffId = isset($cursors[$cursorKey]['last_staff_id'])
                ? (int)$cursors[$cursorKey]['last_staff_id']
                : null;

            $lastPosition = $lastStaffId !== null
                ? array_search($lastStaffId, $staffs, true)
                : false;

            $nextStaffId = $lastPosition === false
                ? $staffs[0]
                : $staffs[($lastPosition + 1) % count($staffs)];

            $cursors[$cursorKey] = [
                'last_staff_id' => $nextStaffId,
                'updated_at' => Carbon::now()->toDateTimeString(),
            ];

            $lockedSetting->cursors = json_encode($cursors, JSON_UNESCAPED_UNICODE);
            $lockedSetting->save();

            $this->setting = $lockedSetting;

            return (int)$nextStaffId;
        });
    }

    protected function resolveTemplate(array $settings): array
    {
        $queueUuid = $this->transaction->queue_uuid ?? null;
        if (is_string($queueUuid) && $queueUuid !== '') {
            foreach ($settings as $index => $queue) {
                if (!is_array($queue)) {
                    continue;
                }

                if (($queue['queue_uuid'] ?? null) === $queueUuid) {
                    return [$queue, (int)$index, $queueUuid];
                }
            }
        }

        $templateIndex = is_numeric($this->transaction->template)
            ? (int)$this->transaction->template
            : null;

        if ($templateIndex !== null && array_key_exists($templateIndex, $settings) && is_array(
                $settings[$templateIndex]
            )) {
            $queue = $settings[$templateIndex];

            return [$queue, $templateIndex, $queue['queue_uuid'] ?? null];
        }

        return [[], null, null];
    }

    protected function resolveTimezone(): string
    {
        return (string)(config('app.timezone') ?: 'Europe/Moscow');
    }
}
