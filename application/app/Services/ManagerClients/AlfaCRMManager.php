<?php

namespace App\Services\ManagerClients;

use App\Models\Account;
use App\Models\User;
use App\Models\Webhook;
use App\Notifications\Api\AlfaCRMAuthException;
use App\Notifications\Api\amoCRMAuthException;
use App\Services\AlfaCRM\Client as alfaApi;
use App\Services\amoCRM\Client as amoApi;
use Exception;
use Illuminate\Support\Facades\Log;

class AlfaCRMManager
{
    public amoApi $amoApi;
    public alfaApi $alfaApi;
    public Account $amoAccount;
    public Account $alfaAccount;

    //TODO exception auth

    /**
     * @throws Exception
     */
    public function __construct(Webhook $webhook)
    {
        $user = $webhook->user;

        $this->amoAccount = $user->amoAccount();

        $this->amoApi = (new amoApi($this->amoAccount));
        $this->amoApi->init();

        if ($this->amoApi->auth == false) {

            $user->notify(new amoCRMAuthException());
        }

        $this->alfaAccount = $user->alfaAccount();

        try {
            $this->alfaApi = (new alfaApi($this->alfaAccount))->init();

        } catch (\Throwable $exception) {

            Log::error(__METHOD__.' : '.$exception->getMessage());

            $user->notify(new AlfaCRMAuthException());
        }
    }

    public static function register(User $user)
    {
        $user->account()->create(['name' => 'alfacrm']);

        $setting = $user->alfaSetting()->create();

        $setting->createWebhooks($user);
    }
}
