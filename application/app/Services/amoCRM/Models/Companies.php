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
        /* -------------------------
         * ТЕЛЕФОНЫ
         * ------------------------- */
        $phones = [];

        // множественные телефоны
        if (!empty($arrayFields['Телефоны']) && is_array($arrayFields['Телефоны'])) {
            foreach ($arrayFields['Телефоны'] as $phone) {
                if (!empty($phone)) {
                    $phones[] = [
                        'value' => $phone,
                        'enum_code' => 'WORK',
                    ];
                }
            }
        }

        // одиночный телефон
        if (!empty($arrayFields['Телефон'])) {
            $phones[] = [
                'value' => $arrayFields['Телефон'],
                'enum_code' => 'WORK',
            ];
        }

        if (!empty($phones)) {
            $fieldName = ($zone === 'ru') ? 'Телефон' : 'Phone';
            $company->cf($fieldName)->setValues($phones);
        }

        /* -------------------------
         * EMAIL
         * ------------------------- */
        $emails = [];

        // множественные email
        if (!empty($arrayFields['Emails']) && is_array($arrayFields['Emails'])) {
            foreach ($arrayFields['Emails'] as $email) {
                if (!empty($email)) {
                    $emails[] = [
                        'value' => $email,
                        'enum_code' => 'WORK',
                    ];
                }
            }
        }

        // одиночный email
        if (!empty($arrayFields['Email'])) {
            $emails[] = [
                'value' => $arrayFields['Email'],
                'enum_code' => 'WORK',
            ];
        }

        if (!empty($arrayFields['Почта'])) {
            $emails[] = [
                'value' => $arrayFields['Почта'],
                'enum_code' => 'WORK',
            ];
        }

        if (!empty($emails)) {
            $company->cf('Email')->setValues($emails);
        }

        /* -------------------------
         * ОТВЕТСТВЕННЫЙ
         * ------------------------- */
        if (!empty($arrayFields['Ответственный'])) {
            $company->responsible_user_id = $arrayFields['Ответственный'];
        }

        /* -------------------------
         * ИМЯ КОМПАНИИ
         * ------------------------- */
        if (!empty($arrayFields['Имя'])) {
            $company->name = $arrayFields['Имя'];
        }

        /* -------------------------
         * ПРОЧИЕ CUSTOM FIELDS
         * ------------------------- */
        if (!empty($arrayFields['cf']) && is_array($arrayFields['cf'])) {
            foreach ($arrayFields['cf'] as $fieldsName => $fieldValue) {
                if (strpos($fieldsName, 'Дата') !== false) {
                    $company->cf($fieldsName)->setData($fieldValue);
                } else {
                    $company->cf($fieldsName)->setValue($fieldValue);
                }
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
