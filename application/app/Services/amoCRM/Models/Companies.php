<?php

namespace App\Services\amoCRM\Models;

use App\Services\amoCRM\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;

abstract class Companies extends Client
{
    /**
     * @throws \Exception
     */
    public static function search($arrayFields, Client $amoApi, $zone = 'ru')
    {
        $companies = null;

        if ($zone == 'ru') {
            if (key_exists('Телефоны', $arrayFields)) {
                foreach ($arrayFields['Телефоны'] as $phone) {
                    if ($phone && strlen($phone) > 9) {
                        $contacts = $amoApi->service
                            ->companies()
                            ->searchByPhone(substr($phone, -10));
                    }
                }
            }

            if (($companies == null || !$companies->first()) &&
                key_exists('Телефон', $arrayFields)) {
                $phone = $arrayFields['Телефон'];

                if ($phone && strlen($phone) > 9) {
                    $companies = $amoApi->service
                        ->companies()
                        ->searchByPhone(substr($phone, -10));
                }
            }

            if (($companies == null || !$companies->first()) &&
                key_exists('Почта', $arrayFields)) {
                if ($arrayFields['Почта']) {
                    $companies = $amoApi->service
                        ->companies()
                        ->searchByEmail($arrayFields['Почта']);
                }
            }

            if ($companies !== null && $companies->first()) {
                return $companies->first();
            } else {
                return null;
            }
        } else {
            return self::searchCom($arrayFields, $amoApi);
        }
    }

    public static function searchCom($arrayFields, Client $amoApi)
    {
        if (key_exists('Телефоны', $arrayFields)) {
            foreach ($arrayFields['Телефоны'] as $phone) {
                if ($phone) {
                    $resp = Http::withHeaders([
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $amoApi->account->access_token,
                    ])->get('https://' . $amoApi->account->subdomain . '.amocrm.com/api/v4/companies?query=' . $phone);
                }
            }
        }

        if (empty($resp->object()->_embedded->contacts[0]->id) && key_exists('Почта', $arrayFields)) {
            if ($arrayFields['Почта']) {
                $resp = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $amoApi->account->access_token,
                ])->get(
                    'https://' . $amoApi->account->subdomain . '.amocrm.com/api/v4/companies?query=' . $arrayFields['Почта']
                );
            }
        }

        if (!empty($resp->object()->_embedded->companies[0]->id)) {
            return $amoApi->service->companies()->find($resp->object()->_embedded->companies[0]->id);
        } else {
            return null;
        }
    }

    public static function get($amoapi, $id)
    {
        return $amoapi->service->companies()->find($id);
    }

    public static function setField($company, string $fieldName, $value)
    {
        try {
            $company->cf($fieldName)->setValue($value);
        } catch (\Throwable $e) {
        }

        return $company;
    }

    public static function update($company, $arrayFields = [], $zone = 'ru')
    {
        if (key_exists('Телефоны', $arrayFields)) {
            foreach ($arrayFields['Телефоны'] as $phone) {
                if ($zone == 'ru') {
                    $company->cf('Телефон')->setValue($phone);
                } else {
                    $company->cf('Phone')->setValue($phone);
                }
            }
        }

        if (key_exists('Телефон', $arrayFields)) {
            $company->cf('Телефон')->setValue($arrayFields['Телефон']);
        }

        if (key_exists('Почта', $arrayFields)) {
            $company->cf('Email')->setValue($arrayFields['Почта']);
        }

        if (key_exists('Email', $arrayFields)) {
            $company->cf('Email')->setValue($arrayFields['Email']);
        }

        if (key_exists('Emails', $arrayFields) && is_array($arrayFields['Emails'])) {
            foreach ($arrayFields['Emails'] as $email) {
                if ($email !== null && $email !== '') {
                    $company->cf('Email')->setValue($email);
                }
            }
        }

        if (key_exists('Ответственный', $arrayFields)) {
            $company->responsible_user_id = $arrayFields['Ответственный'];
        }

        if (key_exists('Имя', $arrayFields)) {
            $company->name = $arrayFields['Имя'];
        }

        if (key_exists('cf', $arrayFields)) {
            foreach ($arrayFields['cf'] as $fieldsName => $fieldValue) {
                if (strpos($fieldsName, 'Дата') == true) {
                    $company->cf($fieldsName)->setData($fieldValue);
                }
                $company->cf($fieldsName)->setValue($fieldValue);
            }
        }
        $company->save();

        return $company;
    }

    public static function clearPhone(?string $phone): ?string
    {
        if ($phone) {
            return trim(str_replace([',', '(', ')', '-', '+', ' '], '', $phone));
        } else {
            return null;
        }
    }

    public static function create(Client $amoapi, $name = 'Неизвестно')
    {
        $company = $amoapi->service
            ->companies()
            ->create();

        $company->name = !$name ? 'Неизвестно' : $name;

        $company->save();

        return $company;
    }

    public static function buildLink($amoApi, int $companyId): string
    {
        return 'https://' . $amoApi->storage->model->subdomain . '.amocrm.' . $amoApi->storage->model->zone . '/companies/detail/' . $companyId;
    }

    public static function getField($company, string $fieldName)
    {
        try {
            return $company->cf($fieldName)->getValue();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
