<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Client;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Ufee\Amo\Models\Contact as ContactModel;

abstract class Contacts
{
    /**
     * @throws \Exception
     */
    public static function updateOrCreate(Client $client, $amoApi, ?int $responsibleUserId = null): ContactModel
    {
        $guard = EntityCreateGuard::forContact($client);
        $contact = $client->contact_id ? static::get($amoApi, $client->contact_id) : null;

        if (!$contact && $guard->exists()) {
            try {
                $contact = $guard->recover(
                    fn (int $id) => static::get($amoApi, $id),
                    fn (int $startedAt) => static::recoverCreated($client, $amoApi, $startedAt),
                );
            } catch (UnconfirmedCreateException $exception) {
                // Contacts may have been merged after a successful creation.
                // Reuse one verified existing contact; never turn uncertainty into a POST.
                $contact = static::reconcileExisting($client, $amoApi);
                if (!$contact) {
                    throw $exception;
                }
            }
        }

        $contact = $contact ?: static::resolveExistingContact($client, $amoApi);

        if (!$contact) {
            $contact = static::create($amoApi, $client, $responsibleUserId, $guard);
        } else {
            // Persist a found/reconciled contact before updates that can fail independently.
            $client->forceFill(['contact_id' => $contact->id])->save();
            $contact = static::update($contact, $client, $amoApi);
        }

        $client->contact_id = $contact->id;
        $client->save();

        return  $contact;
    }

    private static function reconcileExisting(Client $client, \App\Services\amoCRM\Client $amoApi): ?ContactModel
    {
        if ((int) $client->account_id !== (int) $amoApi->account->id
            || (int) $client->user_id !== (int) $amoApi->account->user_id) {
            throw new RuntimeException('YClients contact reconciliation account scope mismatch.');
        }
        $identity = $amoApi->requestV4('GET', '/api/v4/account');
        $accountId = (int) ($identity['id'] ?? 0);
        if ($accountId <= 0 || ((int) $amoApi->account->amo_account_id > 0
            && $accountId !== (int) $amoApi->account->amo_account_id)) {
            throw new RuntimeException('YClients contact reconciliation destination mismatch.');
        }

        $phone = self::phoneSearchKey($client->phone);
        $email = self::normalizeEmail($client->email);
        $candidates = [];
        foreach (array_unique(array_filter([$phone, $email])) as $query) {
            for ($page = 1; $page <= 20; $page++) {
                $response = $amoApi->requestV4('GET', '/api/v4/contacts', [], [
                    'query' => $query, 'limit' => 250, 'page' => $page,
                ]);
                foreach ($response['_embedded']['contacts'] ?? [] as $row) {
                    if ((int) ($row['account_id'] ?? 0) !== $accountId || (int) ($row['id'] ?? 0) <= 0) {
                        throw new RuntimeException('Unexpected contact account during YClients reconciliation.');
                    }
                    if ($row['is_deleted'] ?? false) {
                        continue;
                    }
                    $phoneMatch = false;
                    $emailMatch = false;
                    foreach ($row['custom_fields_values'] ?? [] as $field) {
                        foreach ($field['values'] ?? [] as $value) {
                            $phoneMatch = $phoneMatch || (($field['field_code'] ?? null) === 'PHONE'
                                && $phone !== null && self::phoneSearchKey($value['value'] ?? null) === $phone);
                            $emailMatch = $emailMatch || (($field['field_code'] ?? null) === 'EMAIL'
                                && $email !== null && self::normalizeEmail($value['value'] ?? null) === $email);
                        }
                    }
                    if ($phoneMatch || $emailMatch) {
                        $candidates[(int) $row['id']] = ['phone' => $phoneMatch, 'email' => $emailMatch];
                    }
                }
                if (empty($response['_links']['next'])) {
                    break;
                }
                if ($page === 20) {
                    throw new RuntimeException('Incomplete contact pagination during YClients reconciliation.');
                }
            }
        }

        $both = array_filter($candidates, fn (array $candidate) => $candidate['phone'] && $candidate['email']);
        $matches = $both ?: $candidates;
        if (count($matches) !== 1) {
            return null;
        }
        $id = (int) array_key_first($matches);
        $contact = static::get($amoApi, $id);
        if (!$contact instanceof ContactModel || (int) $contact->id !== $id) {
            throw new RuntimeException('Reconciled amoCRM contact disappeared before YClients update.');
        }

        return $contact;
    }

    private static function recoverCreated(Client $client, \App\Services\amoCRM\Client $amoApi, int $startedAt): ?ContactModel
    {
        $phone = self::phoneSearchKey($client->phone);
        $email = self::normalizeEmail($client->email);
        $candidates = [];

        foreach (['phone' => $phone, 'email' => $email] as $type => $value) {
            if ($value === null) {
                continue;
            }
            $result = AmoTransportRetry::run(fn () => $type === 'phone'
                ? $amoApi->service->contacts()->searchByPhone($value)
                : $amoApi->service->contacts()->searchByEmail($value));

            foreach (self::contactsFromSearchResult($result) as $contact) {
                if ((int) $contact->created_at < $startedAt - 5 || (int) $contact->created_at > $startedAt + 300) {
                    continue;
                }
                if (($phone !== null && !in_array($phone, self::contactPhoneKeys($contact), true))
                    || ($email !== null && !in_array($email, self::contactEmailKeys($contact), true))) {
                    continue;
                }
                $candidates[(int) $contact->id] = $contact;
            }
        }

        return count($candidates) === 1 ? reset($candidates) : null;
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
        $searchErrors = [];

        if ($phone !== null) {
            try {
                $contacts = AmoTransportRetry::run(
                    fn () => $amoApi->service->contacts()->searchByPhone($phone),
                    ['operation' => 'contact_phone_search', 'normalized_phone' => $phone],
                );
                $phoneContacts = self::contactsFromSearchResult($contacts);
            } catch (Throwable $e) {
                $searchErrors[] = $e;
                Log::warning('YClients amoCRM contact phone search failed.', [
                    'phone' => $arrayFields['Телефон'] ?? null,
                    'normalized_phone' => $phone,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($email !== null) {
            try {
                $contacts = AmoTransportRetry::run(
                    fn () => $amoApi->service->contacts()->searchByEmail($email),
                    ['operation' => 'contact_email_search', 'normalized_email' => $email],
                );
                $emailContacts = self::contactsFromSearchResult($contacts);
            } catch (Throwable $e) {
                $searchErrors[] = $e;
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

        if ($phoneContacts === [] && $emailContacts === [] && $searchErrors !== []) {
            throw $searchErrors[0];
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
            // ContactCollection exposes all() through __call. Propagate lazy-load failures:
            // a failed request must never become "no contact, create one".
            $contacts = $contacts->all();
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

    public static function update(
        ContactModel $contact,
        Client $client,
        ?\App\Services\amoCRM\Client $amoApi = null,
    ): ContactModel
    {
        return AmoTransportRetry::run(function (int $attempt) use ($contact, $client, $amoApi): ContactModel {
            $currentContact = $contact;

            if ($attempt > 1 && $amoApi !== null) {
                $currentContact = static::resolveExistingContact($client, $amoApi);

                if (!$currentContact) {
                    throw new RuntimeException('Existing amoCRM contact not found while retrying YClients update.');
                }
            }

            static::fill($currentContact, $client);
            $currentContact->save();

            return $currentContact;
        }, [
            'operation' => 'contact_update',
            'client_id' => $client->client_id,
            'contact_id' => $contact->id,
        ]);
    }

    public static function create(
        \App\Services\amoCRM\Client $amoApi,
        Client $client,
        ?int $responsibleUserId = null,
        ?EntityCreateGuard $guard = null,
    ): ContactModel
    {
        $contact = $amoApi->service->contacts()->create();
        $contact->name = 'Клиент YClients';

        if ($responsibleUserId) {
            $contact->responsible_user_id = $responsibleUserId;
        }

        static::fill($contact, $client);

        $save = function () use ($contact): ContactModel {
            $contact->save();
            return $contact;
        };
        // Field metadata may require reads. Claim only immediately before the POST.
        $guard ? $guard->create($save) : $save();

        return $contact;
    }

    private static function fill(ContactModel $contact, Client $client): void
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
    }

    public static function get($client, $id)
    {
        return AmoTransportRetry::run(
            fn () => $client->service->contacts()->find($id),
            ['operation' => 'contact_get', 'contact_id' => $id],
        );
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
