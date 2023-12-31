<?php

namespace App\Services\Distribution\Strategies;

use App\Models\Integrations\Distribution\Setting;
use App\Models\Integrations\Distribution\Transaction;
use App\Models\User;
use App\Services\amoCRM\Client;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class BaseStrategy
{
    public User $user;
    public Setting $setting;
    public Transaction $transaction;

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
        $this->staffs = json_decode($this->setting->settings, true)[$this->transaction->template]['staffs'];

        return $this;
    }

    public function setTransactions(Carbon $dateAt = null): static
    {
        $dateAt = $dateAt !== null ? $dateAt : Carbon::now()->timezone('Europe/Moscow');

        $this->transactions = Transaction::query()
            ->where('created_at', '>', $dateAt->format('Y-m-d').' 00:00:00')
            ->where('user_id', $this->user->id)
            ->where('status', true)
            ->where('type', static::$strategy)
            ->get();

        return $this;
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
