<?php

namespace App\Services\amoCRM\Models;

use App\Services\amoCRM\Client;
use Illuminate\Support\Facades\Log;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;

abstract class Contacts extends Client
{
    /**
     * @throws \Exception
     */
    public static function search($arrayFields, Client $amoApi)
    {
        $contacts = null;

        if(key_exists('Телефоны', $arrayFields)) {

            foreach ($arrayFields['Телефоны'] as $phone) {

                if ($phone)
                    $contacts = $amoApi->service
                        ->contacts()
                        ->searchByPhone(substr($phone, -10));
            }
        }

        if(($contacts == null || !$contacts->first()) &&
            key_exists('Почта', $arrayFields)) {

            if ($arrayFields['Почта'])

                $contacts = $amoApi->service
                    ->contacts()
                    ->searchByEmail($arrayFields['Почта']);
        }

        if($contacts !== null && $contacts->first())

            return $contacts->first();
        else
            return null;
    }

    public static function setField(Contact $contact, string $fieldName, $value): Contact
    {
        try {
            $contact->cf($fieldName)->setValue($value);

        } catch (\Throwable $e) {}

        return $contact;
    }

    public static function update(Contact $contact, $arrayFields = [], $zone = 'ru')
    {
        if(key_exists('Телефоны', $arrayFields)) {

            foreach ($arrayFields['Телефоны'] as $phone) {

                if ($zone == 'ru')
                    $contact->cf('Телефон')->setValue($phone);
                else
                    $contact->cf('Phone')->setValue($phone);
            }
        }

        if(key_exists('Почта', $arrayFields)) {

            $contact->cf('Email')->setValue($arrayFields['Почта']);
        }

        if(key_exists('Ответственный', $arrayFields)) {

            $contact->responsible_user_id = $arrayFields['Ответственный'];
        }

        if(key_exists('Имя', $arrayFields)) {

            $contact->name = $arrayFields['Имя'];
        }

        if(key_exists('cf', $arrayFields)) {

            foreach ($arrayFields['cf'] as $fieldsName => $fieldValue) {

                if(strpos($fieldsName, 'Дата') == true) {

                    $contact->cf($fieldsName)->setData($fieldValue);
                }
                $contact->cf($fieldsName)->setValue($fieldValue);
            }
        }
        $contact->save();

        return $contact;
    }

    public static function clearPhone(?string $phone): ?string
    {
        if ($phone) {

            return trim(str_replace([',', '(', ')', '-', '+', ' '],'', $phone));
        } else
            return null;
    }

    public static function create(Client $amoapi, $name = 'Неизвестно')
    {
        $contact = $amoapi->service
            ->contacts()
            ->create();

        $contact->name = !$name ? 'Неизвестно' : $name;

        $contact->save();

        return $contact;
    }

    public static function get($amoapi, $id)
    {
        return $amoapi->service->contacts()->find($id);
    }

    public static function buildLink($amoApi, int $contactId) : string
    {
        return 'https://'.$amoApi->storage->model->subdomain.'.amocrm.'.$amoApi->storage->model->zone.'/contacts/detail/'.$contactId;
    }

    public static function getField(Contact $contact, string $fieldName)
    {
        try {
            return $contact->cf($fieldName)->getValue();

        } catch (\Throwable $e) {

            return null;
        }
    }
}
