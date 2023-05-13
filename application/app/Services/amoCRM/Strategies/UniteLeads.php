<?php

namespace App\Services\amoCRM\Strategies;

use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use Illuminate\Database\Eloquent\Model;
use Ufee\Amo\Models\Lead;

class UniteLeads
{
    public function run(Model $model, Model $setting, Client $amoApi) : Lead
    {
        $contact = Contacts::search([
            'Телефоны' => [$model->phone],
            'Почта'    => $model->email,
        ], $amoApi) ?? Contacts::create($amoApi);

        $contact = Contacts::update($contact, [
            'Имя' => $model->name,
            'Ответственный' => $setting->responsible_user_id,//TODO
        ]);

        return Leads::search($contact, $amoApi) ?? Leads::create($amoApi);
    }
}
