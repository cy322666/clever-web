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
        /* =======================
         * ТЕЛЕФОНЫ
         * ======================= */
        $phones = [];

        if (!empty($arrayFields['Телефоны']) && is_array($arrayFields['Телефоны'])) {
            foreach ($arrayFields['Телефоны'] as $phone) {
                if (!empty($phone)) {
                    $phones[] = ['value' => $phone];
                }
            }
        }

        if (!empty($arrayFields['Телефон'])) {
            $phones[] = ['value' => $arrayFields['Телефон']];
        }

        if (!empty($phones)) {
            $fieldName = ($zone === 'ru') ? 'Телефон' : 'Phone';
            $company->cf($fieldName)->setValue($phones);
        }

        /* =======================
         * EMAIL
         * ======================= */
        $emails = [];

        if (!empty($arrayFields['Emails']) && is_array($arrayFields['Emails'])) {
            foreach ($arrayFields['Emails'] as $email) {
                if (!empty($email)) {
                    $emails[] = ['value' => $email];
                }
            }
        }

        if (!empty($arrayFields['Email'])) {
            $emails[] = ['value' => $arrayFields['Email']];
        }

        if (!empty($arrayFields['Почта'])) {
            $emails[] = ['value' => $arrayFields['Почта']];
        }

        if (!empty($emails)) {
            $company->cf('Email')->setValue($emails);
        }

        /* =======================
         * ОСТАЛЬНОЕ
         * ======================= */
        if (!empty($arrayFields['Ответственный'])) {
            $company->responsible_user_id = $arrayFields['Ответственный'];
        }

        if (!empty($arrayFields['Имя'])) {
            $company->name = $arrayFields['Имя'];
        }

        if (!empty($arrayFields['cf']) && is_array($arrayFields['cf'])) {
            foreach ($arrayFields['cf'] as $fieldName => $fieldValue) {
                if (strpos($fieldName, 'Дата') !== false) {
                    $company->cf($fieldName)->setData($fieldValue);
                } else {
                    $company->cf($fieldName)->setValue($fieldValue);
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
