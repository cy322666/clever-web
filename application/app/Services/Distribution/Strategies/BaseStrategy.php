<?php

namespace App\Services\Distribution\Strategies;

use App\Models\amoCRM\Staff;
use App\Models\Integrations\Distribution\Setting;
use App\Models\Integrations\Distribution\Transaction;
use App\Models\User;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Leads;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Ufee\Amo\Base\Services\Model;
use Ufee\Amo\Models\Lead;

class BaseStrategy
{
    public User $user;
    public Setting $setting;
    public Transaction $transaction;

    public ?array $template = [];
    public ?array $staffs = [];

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

        $this->template = json_decode($this->setting->settings, true)[$this->transaction->template] ?? [];

        $this->type = $this->transaction->type;
        $this->staffs = $this->template['staffs'] ?? [];

        return $this;
    }

    public function setTransactions(Carbon $dateAt = null): static
    {
        $dateAt = $dateAt !== null ? $dateAt : Carbon::now()->timezone('Europe/Moscow');

        $query = Transaction::query()
            ->where('created_at', '>', $dateAt->format('Y-m-d').' 00:00:00')
            ->where('user_id', $this->transaction->user_id)
            ->where('status', true)
            ->where('template', $this->transaction->template)
            ->where('id', '<', $this->transaction->id)
            ->orderBy('id', 'ASC');

        $this->transactions = $query->get();

        Log::debug(__METHOD__.' user_id '.$this->user->id, ['query' => $query->toRawSql()]);

        return $this;
    }

    public function sliceSchedule()
    {
        //по графику?
        if ($this->transaction->schedule) {

            Log::debug(__METHOD__.' ДО отбора по рабочему графику  : ', [$this->staffs]);

            $now = Carbon::now()->timezone('Europe/Moscow');

            //отбираем только тех, кто работает по графику сейчас
            foreach ($this->staffs as $staff) {

                $isWork = false;

                $staff = Staff::query()->where('staff_id', $staff)->first();

                $schedulers = $staff->schedule->settings ?? null;

                if ($schedulers) {

                    $schedulers = json_decode($schedulers);

                    Log::info(__METHOD__.' staff_id => '.$staff->id, $schedulers);

                    foreach ($schedulers as $scheduler) {

                        if ($scheduler->type == 'work') {

                            $at = Carbon::parse($scheduler->at);
                            $to = Carbon::parse($scheduler->to);

                            if ($now > $at && $now < $to) {

                                Log::info(__METHOD__.' staff_id => '.$staff->id.' is work');

                                $isWork = true;
                            }
                        }
                    }
                }

                if (!$isWork)
                    unset($this->staffs[$staff->staff_id]);
            }

            Log::debug(__METHOD__.' ПОСЛЕ отбора по рабочему графику остались : ', [$this->staffs]);
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
    public function checkActiveGetStaff(Client $amoApi, Transaction $transaction) : bool
    {
        if ($transaction->contact_id) {

            if (array_key_exists('check_active', $this->template) && $this->template['check_active'] == 'yes')

            $contact = $amoApi->service->contacts()->find($transaction->contact_id);

            if ($contact)

                $leads = Leads::searchActiveLeads($contact);

                if (count($leads) > 1)

                    foreach ($leads as $lead) {

                        if ($lead->id !== $transaction->lead_id)

                            if (in_array($this->staffs, $lead->responsible_user_id))

                                return $lead->responsible_user_id;
                    }
        }

        return false;
    }
}
