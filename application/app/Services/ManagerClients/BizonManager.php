<?php

namespace App\Services\ManagerClients;

use App\Models\User;
use App\Models\Webhook;
use App\Notifications\Api\amoCRMAuthException;
use App\Notifications\Api\BizonAuthException;
use App\Services\amoCRM\Client as amoApi;
use App\Services\Bizon\Client;
use Illuminate\Support\Facades\Log;

class BizonManager
{
    public Client $bizonApi;
    public $bizonAccount;
    public $amoAccount;
    public amoApi $amoApi;

    public function __construct(Webhook $webhook)
    {
        $user = $webhook->user;

        $this->amoAccount = $user->amoAccount();

        $this->amoApi = (new amoApi($this->amoAccount));
        $this->amoApi->init();

        if ($this->amoApi->auth == false) {

            $user->notify(new amoCRMAuthException());
        }

        $this->bizonAccount = $user->bizonAccount();

        try {
            $this->bizonApi = (new Client())
                ->setToken($webhook->user->bizonAccount()->access_token);

        } catch (\Throwable $exception) {

            Log::error(__METHOD__.' : '.$exception->getMessage());

            $user->notify(new BizonAuthException());
        }
    }

    public static function register(User $user)
    {
        $user->account()->create(['name' => 'bizon']);

        $setting = $user->bizonSetting()->create();

        $setting->createWebhooks($user);
    }
}
