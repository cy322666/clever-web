<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Client;
use Illuminate\Support\Facades\Log;
use Throwable;
use Ufee\Amo\Models\Contact as ContactModel;

abstract class Contacts
{
    /**
     * @throws \Exception
     */
    public static function updateOrCreate(Client $client, $amoApi, ?int $responsibleUserId = null): ContactModel
    {
        $contact = static::resolveExistingContact($client, $amoApi);

        if (!$contact) {
            $contact = static::create($amoApi, $responsibleUserId);
            $contact = static::update($contact, $client);
        } else {
            $contact = static::update($contact, $client);
        }

        $client->contact_id = $contact->id;
        $client->save();

        return  $contact;
    }

    /**
     * @throws \Exception
     */
    public static function search(array $arrayFields, \App\Services\amoCRM\Client $amoApi): ?ContactModel
    {
        $phone = self::phoneSearchKey($arrayFields['Телефон'] ?? null);
        $email = self::normalizeEmail($arrayFields['Почта'] ?? null);
        $phoneContacts = [];
        $emailContacts = [];

        if ($phone !== null) {
            try {
                $contacts = $amoApi->service
                    ->contacts()
                    ->searchByPhone($phone);
                $phoneContacts = self::contactsFromSearchResult($contacts);
            } catch (Throwable $e) {
                Log::warning('YClients amoCRM contact phone search failed.', [
                    'phone' => $arrayFields['Телефон'] ?? null,
                    'normalized_phone' => $phone,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($email !== null) {
            try {
                $contacts = $amoApi->service
                    ->contacts()
                    ->searchByEmail($email);
                $emailContacts = self::contactsFromSearchResult($contacts);
            } catch (Throwable $e) {
                Log::warning('YClients amoCRM contact email search failed.', [
                    'email' => $arrayFields['Почта'] ?? null,
                    'normalized_email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($phoneContacts as $contact) {
            if ($email !== null && in_array($email, self::contactEmailKeys($contact), true)) {
                return $contact;
            }
        }

        foreach ($emailContacts as $contact) {
            if ($phone !== null && in_array($phone, self::contactPhoneKeys($contact), true)) {
                return $contact;
            }
        }

        return $phoneContacts[0] ?? $emailContacts[0] ?? null;
    }

    private static function firstContact(mixed $contacts): ?ContactModel
    {
        if ($contacts instanceof ContactModel) {
            return $contacts;
        }

        if (!is_object($contacts)) {
            return null;
        }

        try {
            $contact = $contacts->first();
        } catch (Throwable) {
            return null;
        }

        return $contact instanceof ContactModel ? $contact : null;
    }

    /**
     * @return array<int, ContactModel>
     */
    private static function contactsFromSearchResult(mixed $contacts): array
    {
        if ($contacts instanceof ContactModel) {
            return [$contacts];
        }

        if (is_object($contacts)) {
            try {
                // ContactCollection exposes all() through __call, so method_exists() is false.
                $contacts = $contacts->all();
            } catch (Throwable) {
                return [];
            }
        }

        if (!is_iterable($contacts)) {
            return [];
        }

        $result = [];

        foreach ($contacts as $contact) {
            if ($contact instanceof ContactModel) {
                $result[] = $contact;
            }
        }

        return $result;
    }

    public static function update($contact, Client $client)
    {
        if (filled($client->name)) {
            $contact->name = $client->name;
        }

        if (filled($client->email)) {
            $contact->cf('Email')->setValue(self::normalizeEmail($client->email));
        }

        if (filled($client->phone)) {
            $contact->cf('Телефон')->setValue(self::clearPhone($client->phone, true));
        }

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
        $digits = self::phoneDigits($phone);

        if (!$digits) {
            return null;
        }

        if ($preserveLeadingPlus) {
            return self::phoneForStore($phone);
        }

        return self::phoneSearchKey($phone);
    }

    private static function resolveExistingContact(Client $client, \App\Services\amoCRM\Client $amoApi): ?ContactModel
    {
        return static::search([
            'Телефон' => $client->phone,
            'Почта' => $client->email,
        ], $amoApi);
    }

    private static function contactPhoneKeys(ContactModel $contact): array
    {
        try {
            return collect($contact->cf('Телефон')->getValues())
                ->map(fn($phone): ?string => self::phoneSearchKey($phone))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private static function contactEmailKeys(ContactModel $contact): array
    {
        try {
            return collect($contact->cf('Email')->getValues())
                ->map(fn($email): ?string => self::normalizeEmail($email))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private static function phoneSearchKey(?string $phone): ?string
    {
        $digits = self::phoneDigits($phone);

        if (!$digits) {
            return null;
        }

        $phone = substr($digits, -10);

        return strlen($phone) >= 6 ? $phone : null;
    }

    private static function phoneForStore(?string $phone): ?string
    {
        $digits = self::phoneDigits($phone);

        if (!$digits) {
            return null;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '+7' . $digits;
        }

        if (strlen($digits) === 11 && (str_starts_with($digits, '7') || str_starts_with($digits, '8'))) {
            return '+7' . substr($digits, -10);
        }

        return str_starts_with(trim((string)$phone), '+') ? '+' . $digits : $digits;
    }

    private static function phoneDigits(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return $digits !== '' ? $digits : null;
    }

    private static function normalizeEmail(mixed $email): ?string
    {
        if (!is_scalar($email)) {
            return null;
        }

        $email = mb_strtolower(trim((string)$email));

        return $email !== '' && str_contains($email, '@') ? $email : null;
    }
}
