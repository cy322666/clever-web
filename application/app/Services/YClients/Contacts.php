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
    public static function search(array $arrayFields, \App\Services\amoCRM\Client $amoApi)
    {
        $contacts = null;
        $phone = self::phoneSearchKey($arrayFields['Телефон'] ?? null);
        $email = self::normalizeEmail($arrayFields['Почта'] ?? null);

        if ($phone !== null) {
            try {
                $contacts = $amoApi->service
                    ->contacts()
                    ->searchByPhone($phone);
            } catch (Throwable $e) {
                Log::warning('YClients amoCRM contact phone search failed.', [
                    'phone' => $arrayFields['Телефон'] ?? null,
                    'normalized_phone' => $phone,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (($contacts === null || $contacts->first() === null) && $email !== null) {
            try {
                $contacts = $amoApi->service
                    ->contacts()
                    ->searchByEmail($email);
            } catch (Throwable $e) {
                Log::warning('YClients amoCRM contact email search failed.', [
                    'email' => $arrayFields['Почта'] ?? null,
                    'normalized_email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($contacts !== null && $contacts->first() !== null)
            return $contacts->first();

        return null;
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
        if (!empty($client->contact_id)) {
            $contact = static::get($amoApi, $client->contact_id);

            if ($contact && self::contactMatchesClient($contact, $client)) {
                return $contact;
            }

            Log::warning('YClients stored contact_id does not match client phone/email, searching contact again.', [
                'yclients_client_id' => $client->id,
                'stored_contact_id' => $client->contact_id,
                'account_id' => $client->account_id,
                'setting_id' => $client->setting_id,
                'client_id' => $client->client_id,
            ]);
        }

        return static::findLinkedContact($client, $amoApi)
            ?: static::search([
                'Телефон' => $client->phone,
                'Почта' => $client->email,
            ], $amoApi);
    }

    private static function findLinkedContact(Client $client, \App\Services\amoCRM\Client $amoApi): ?ContactModel
    {
        $phone = self::phoneSearchKey($client->phone);
        $email = self::normalizeEmail($client->email);

        if ($phone === null && $email === null) {
            return null;
        }

        $matches = Client::query()
            ->where('account_id', $client->account_id)
            ->whereNotNull('contact_id')
            ->where('id', '!=', $client->id)
            ->where(function ($query) use ($phone, $email): void {
                if ($phone !== null) {
                    $query->orWhereRaw(
                        "right(regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g'), 10) = ?",
                        [$phone]
                    );
                }

                if ($email !== null) {
                    $query->orWhereRaw('lower(trim(email)) = ?', [$email]);
                }
            })
            ->orderBy('created_at')
            ->pluck('contact_id')
            ->filter()
            ->unique()
            ->values();

        foreach ($matches as $contactId) {
            $contact = static::get($amoApi, (int)$contactId);

            if ($contact && self::contactMatches($contact, $phone, $email)) {
                return $contact;
            }
        }

        return null;
    }

    private static function contactMatchesClient(ContactModel $contact, Client $client): bool
    {
        return self::contactMatches(
            $contact,
            self::phoneSearchKey($client->phone),
            self::normalizeEmail($client->email)
        );
    }

    private static function contactMatches(ContactModel $contact, ?string $phone, ?string $email): bool
    {
        if ($phone !== null && in_array($phone, self::contactPhoneKeys($contact), true)) {
            return true;
        }

        if ($email !== null && in_array($email, self::contactEmailKeys($contact), true)) {
            return true;
        }

        return false;
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
