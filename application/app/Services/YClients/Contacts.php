<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Client;
use Ufee\Amo\Models\Contact as ContactModel;

abstract class Contacts
{
    /**
     * @throws \Exception
     */
    public static function updateOrCreate(Client $client, $amoApi, ?int $responsibleUserId = null): ContactModel
    {
        $contact = Contacts::search([
            'Телефон' => $client->phone,
            'Почта'   => $client->email,
        ], $amoApi);

        if (!$contact) {
            $contact = static::create($amoApi, $responsibleUserId);
            $contact = static::update($contact, $client);
        } else
            $contact = static::update($contact, $client);

        $client->contact_id = $contact->id;
        $client->save();

        return  $contact;
    }

    /**
     * @throws \Exception
     */
    public static function search(array $arrayFields, \App\Services\amoCRM\Client $amoApi)
    {
        $contacts = null;

        if(key_exists('Телефон', $arrayFields) && $arrayFields['Телефон'] !== null)

            $contacts = $amoApi->service
                ->contacts()
                ->searchByPhone(self::clearPhone($arrayFields['Телефон']));

        if ($contacts == null || $contacts->first() == null) {

            if(key_exists('Почта', $arrayFields) && $arrayFields['Почта'] !== null)

                $contacts = $amoApi->service
                    ->contacts()
                    ->searchByEmail($arrayFields['Почта']);
        }

        if ($contacts !== null && $contacts->first() !== null)
            return $contacts->first();

        return null;
    }

    public static function update($contact, Client $client)
    {
        $contact->name = $client->name;
        $contact->cf('Email')->setValue($client->email);
        $contact->cf('Телефон')->setValue(self::clearPhone($client->phone, true));
        $contact->save();

        return $contact;
    }

    public static function create(\App\Services\amoCRM\Client $amoApi, ?int $responsibleUserId = null)
    {
        $contact = $amoApi->service->contacts()->create();
        $contact->name = 'Клиент YClients';

        if ($responsibleUserId) {
            $contact->responsible_user_id = $responsibleUserId;
        }

        $contact->save();

        return $contact;
    }

    public static function get($client, $id)
    {
        return $client->service->contacts()->find($id);
    }

    public static function buildLink($amoApi, int $contactId) : string
    {
        return 'https://'.$amoApi->storage->model->subdomain.'.amocrm.ru/contacts/detail/'.$contactId;
    }

    public static function clearPhone(?string $phone, bool $preserveLeadingPlus = false): ?string
    {
        if ($phone) {
            $normalized = trim(str_replace([',', '(', ')', '-', ' '], '', $phone));

            if ($preserveLeadingPlus && str_starts_with($normalized, '+')) {
                return '+' . preg_replace('/\D+/', '', substr($normalized, 1));
            }

            return substr(preg_replace('/\D+/', '', $normalized), -10);
        } else
            return null;
    }
}
