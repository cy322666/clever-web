<?php

namespace App\Models\Integrations\YClients;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Client extends Model
{
    protected $fillable = [
        'user_id',
        'account_id',
        'setting_id',

        'client_id',
        'name',
        'phone',
        'email',
        'birth_date',
        'spent',
        'company_id',
        'visits',
        'spent',
        'contact_id',
        'last_record',
        'body',
    ];

    protected $table = 'yclients_clients';

    public static function buildArrayForModel($arrayRequest = null)
    {
        if(!empty($arrayRequest['data']['client']['id'])) {

            $arrayForModel = [
                'company_id' => $arrayRequest['company_id'],
                'client_id'  => $arrayRequest['data']['client']['id'],
                'name'  => $arrayRequest['data']['client']['name'],
                'phone' => $arrayRequest['data']['client']['phone'],
            ];

            if (!empty($arrayRequest['data']['client']['email']))

                $arrayForModel = array_merge($arrayForModel, [
                    'email' => $arrayRequest['data']['client']['email']
                ]);

            if (!empty($arrayRequest['data']['client']['success_visits_count']))

                $arrayForModel = array_merge($arrayForModel, [
                    'success_visits_count' => $arrayRequest['data']['client']['success_visits_count']
                ]);

            return $arrayForModel;
        } else {

            Log::warning(__METHOD__.": нет контакта в записи # ".$arrayRequest['resource_id']);
        }
    }

    public function records(): HasMany
    {
        return $this->hasMany('App\Models\Record');
    }
}

