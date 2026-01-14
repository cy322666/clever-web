<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Client;
use Ufee\Amo\Models\Contact as ContactModel;

abstract class Contacts
{
    public static function updateOrCreate(Client $client, $amoApi): ContactModel
    {
        $contact = Contacts::search([
            'Телефон' => $client->phone,
            'Почта'   => $client->email,
        ], $amoApi);

        $contact = $contact ? static::update($contact, $client) : static::create($client);

        $client->contact_id = $contact->id;
        $client->save();

        return  $contact;
    }

    public static function search($arrayFields, $client)
    {
        $contacts = null;

        if(key_exists('Телефон', $arrayFields) && $arrayFields['Телефон'] !== null)

            $contacts = $client->service
                ->contacts()
                ->searchByPhone(self::clearPhone($arrayFields['Телефон']));

        if ($contacts == null || $contacts->first() == null) {

            if(key_exists('Почта', $arrayFields) && $arrayFields['Почта'] !== null)

                $contacts = $client->service
                    ->contacts()
                    ->searchByEmail($arrayFields['Почта']);
        }

        if ($contacts !== null && $contacts->first() !== null) {
            return $contacts->first();
        }

        return null;
    }

    public static function update($contact, $client)
    {
        $contact->name = $client->name;
        $contact->cf('Email')->setValue($client->email);
        $contact->cf('Телефон')->setValue($client->phone);
        $contact->save();

        return $contact;
    }

    //TODO это в команде обновления
//    public static function update($contact, $arrayFields = [])
//    {
//        if(key_exists('cf', $arrayFields)) {
//
//            foreach ($arrayFields['cf'] as $fieldsName => $fieldValue) {
//
//                if(strpos($fieldsName, 'Дата')) {
//
//                    $contact->cf($fieldsName)->setData($fieldValue);
//                }
//                $contact->cf($fieldsName)->setValue($fieldValue);
//            }
//        }
//
//        $contact->save();
//
//        return $contact;
//    }

    public static function create(Client $amoApi)
    {
        $contact = $amoApi->service
            ->contacts()
            ->create();

        return static::update($contact, $amoApi);
    }

    public static function get($client, $id)
    {
        return $client->service->contacts()->find($id);
    }

    public static function buildLink($amoApi, int $contactId) : string
    {
        return 'https://'.$amoApi->storage->model->subdomain.'.amocrm.ru/contacts/detail/'.$contactId;
    }

    public static function clearPhone(?string $phone): ?string
    {
        if ($phone) {

            return substr(str_replace([',', '(', ')', '-', '+', ' '],'', $phone), -10);
        } else
            return null;
    }
}
