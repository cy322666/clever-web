<?php

namespace App\Services\Distribution\Strategies;

use App\Models\Integrations\Distribution\Setting;
use App\Models\Integrations\Distribution\Transaction;
use App\Models\User;
use App\Services\amoCRM\Client;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Ufee\Amo\Base\Services\Model;
use Ufee\Amo\Models\Lead;

class BaseStrategy
{
    public User $user;
    public Setting $setting;
    public Transaction $transaction;

    public ?array $template = [];

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

        $this->transactions = Transaction::query()
            ->where('created_at', '>', $dateAt->format('Y-m-d').' 00:00:00')
            ->where('user_id', $this->user->id)
            ->where('status', true)
            ->where('template', $this->transaction->template)//schedule_yes
            ->get();

        return $this;
    }

    public function sliceSchedule()
    {
        //по графику?
        if ($this->transaction->schedule) {

            $now = Carbon::now()->timezone('Europe/Moscow');
            $isWork = false;

            //отбираем только тех, кто работает по графику сейчас
            foreach ($this->staffs as $staff) {

                $schedulers = $staff->schedule->setting ?? null;

                if ($schedulers) {

                    $schedulers = json_decode($schedulers);

                    foreach ($schedulers as $scheduler) {

                        if ($scheduler->type == 'work') {

                            $at = Carbon::parse($scheduler->at);
                            $to = Carbon::parse($scheduler->to);

                            if ($now > $at && $now < $to) {

                                $isWork = true;
                            }
                        }
                    }
                }

                if (!$isWork)
                    unset($this->staffs[$staff]);
            }
        }
        return $this;
    }

    /**
     * @param Client $amoApi
     * @param int $staff
     * @return \Ufee\Amo\Base\Collections\Collection|Model|Lead|null
     * @throws \Exception
     */
    public function changeResponsible(Client $amoApi, int $staff): \Ufee\Amo\Base\Collections\Collection|Model|null|Lead
    {
        $lead = $amoApi->service
            ->leads()
            ->find($this->transaction->lead_id);

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

        return $lead;
    }
}
