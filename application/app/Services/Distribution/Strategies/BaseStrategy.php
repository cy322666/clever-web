<?php

namespace App\Services\Distribution;

use App\Models\Integrations\Distribution\Setting;
use App\Models\Integrations\Distribution\Transaction;
use App\Models\User;
use App\Services\amoCRM\Client;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Ufee\Amo\Base\Services\Model;

class BaseStrategy
{
    public User $user;
    public Transaction $setting;
    public Setting $transaction;

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

        $this->type = $this->transaction->type;
        $this->staffs = json_decode($this->setting->settings, true)[$this->transaction->template];

        return $this;
    }

    public function setTransactions(Carbon $dateAt = null): static
    {
        $dateAt = $dateAt !== null ? $dateAt : Carbon::now()->timezone('Europe/Moscow');

        $this->transactions = Transaction::query()
            ->where('created_at', $dateAt->format('Y-m-d'))
            ->where('user_id', $this->user->id)
            ->where('status', true)
            ->where('type', $this->strategy)
            ->get();

        return  $this;
    }

    public function changeResponsuble(Client $amoApi, int $staff)
    {
        $lead = $amoApi->service
            ->leads()
            ->find($this->transaction->lead_id);

        $lead->responsible_user_id = $staff ?? $lead->responsible_user_id;
        $lead->save();

        return $lead;
    }
}
