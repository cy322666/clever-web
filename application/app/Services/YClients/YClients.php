<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Client;
use App\Models\Integrations\YClients\Setting;
use App\Models\Integrations\YClients\YClients as YC;

class YClients
{
    public static function instance(Setting $setting): YC
    {
        //TODO из настроек
        $yclients = new YC($setting->token);
        $yclients->getAuth($setting->login, $setting->password);

        return $yclients;
    }

    public static function getClient(Client $client): array
    {
        $yclients = self::instance();

        return $yclients->getClient(
            $client->company_id,
            $client->client_id,
            env('YC_USER_TOKEN')//TODO
        );
    }
}
