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
        if ($zone !== 'ru') {
            return self::searchCom($arrayFields, $amoApi);
        }

        /* =======================
         * СОБИРАЕМ ТЕЛЕФОНЫ
         * ======================= */
        $phones = [];

        if (!empty($arrayFields['Телефоны']) && is_array($arrayFields['Телефоны'])) {
            foreach ($arrayFields['Телефоны'] as $phone) {
                if ($phone && strlen($phone) > 9) {
                    $phones[] = $phone;
                }
            }
        }

        if (!empty($arrayFields['Телефон']) && strlen($arrayFields['Телефон']) > 9) {
            $phones[] = $arrayFields['Телефон'];
        }

        // нормализуем + убираем дубли
        $phones = array_values(
            array_unique(
                array_map(
                    fn($p) => substr(preg_replace('/\D+/', '', $p), -10),
                    $phones
                )
            )
        );

        /* =======================
         * ИЩЕМ ПО ТЕЛЕФОНАМ
         * ======================= */
        foreach ($phones as $phone) {
            if (strlen($phone) === 10) {
                $companies = $amoApi->service
                    ->companies()
                    ->searchByPhone($phone);

                if ($companies && $companies->first()) {
                    return $companies->first();
                }
            }
        }

        /* =======================
         * СОБИРАЕМ EMAIL
         * ======================= */
        $emails = [];

        if (!empty($arrayFields['Emails']) && is_array($arrayFields['Emails'])) {
            foreach ($arrayFields['Emails'] as $email) {
                if (!empty($email)) {
                    $emails[] = $email;
                }
            }
        }

        if (!empty($arrayFields['Email'])) {
            $emails[] = $arrayFields['Email'];
        }

        if (!empty($arrayFields['Почта'])) {
            $emails[] = $arrayFields['Почта'];
        }

        $emails = array_values(array_unique($emails));

        /* =======================
         * ИЩЕМ ПО EMAIL
         * ======================= */
        foreach ($emails as $email) {
            if ($email) {
                $companies = $amoApi->service
                    ->companies()
                    ->searchByEmail($email);

                if ($companies && $companies->first()) {
                    return $companies->first();
                }
            }
        }

        return null;
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

    private static function setMultiCf($entity, string $fieldName, array $values, ?string $enum = null): void
    {
        $values = array_values(
            array_filter(
                array_map(
                    fn($v) => is_string($v) ? trim($v) : $v,
                    $values
                ),
                fn($v) => $v !== null && $v !== ''
            )
        );

        if (!$values) {
            return;
        }

        $cf = $entity->cf($fieldName);

        // 1) первое значение задаём через setValue (ufee это точно умеет)
        if ($enum !== null) {
            // некоторые версии поддерживают 2й аргумент enum
            try {
                $cf->setValue($values[0], $enum);
            } catch (\Throwable $e) {
                $cf->setValue($values[0]);
            }
        } else {
            $cf->setValue($values[0]);
        }

        // 2) остальные добавляем через addValue/add
        for ($i = 1; $i < count($values); $i++) {
            $val = $values[$i];

            if (method_exists($cf, 'addValue')) {
                $enum !== null ? $cf->addValue($val, $enum) : $cf->addValue($val);
                continue;
            }

            if (method_exists($cf, 'add')) {
                $enum !== null ? $cf->add($val, $enum) : $cf->add($val);
                continue;
            }

            // Если нет addValue/add — значит твоя версия ufee физически не умеет мультизначные через fluent API
            // Тогда только последнее значение останется (лучше так, чем падать)
            $cf->setValue($val);
        }
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
                    $phones[] = $phone;
                }
            }
        }

        if (!empty($arrayFields['Телефон'])) {
            $phones[] = $arrayFields['Телефон'];
        }

        $phones = array_values(array_unique($phones));

        if (!empty($phones)) {
            $phoneField = ($zone === 'ru') ? 'Телефон' : 'Phone';
            self::setMultiCf($company, $phoneField, $phones, 'WORK');
        }

        /* =======================
         * EMAIL
         * ======================= */
        $emails = [];

        if (!empty($arrayFields['Emails']) && is_array($arrayFields['Emails'])) {
            foreach ($arrayFields['Emails'] as $email) {
                if (!empty($email)) $emails[] = $email;
            }
        }

        if (!empty($arrayFields['Email'])) {
            $emails[] = $arrayFields['Email'];
        }

        if (!empty($arrayFields['Почта'])) {
            $emails[] = $arrayFields['Почта'];
        }

        $emails = array_values(array_unique($emails));

        if (!empty($emails)) {
            self::setMultiCf($company, 'Email', $emails, 'WORK');
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

        if (!empty($arrayFields) && is_array($arrayFields)) {
            foreach ($arrayFields as $fieldsName => $fieldValue) {
                try {
                    if (strpos($fieldsName, 'Дата') !== false) {
                        $company->cf($fieldsName)->setData($fieldValue);
                    } else {
                        $company->cf($fieldsName)->setValue($fieldValue);
                    }
                } catch (\Throwable $e) {
                    Log::error(__METHOD__ . ' ' . $e->getMessage());
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
