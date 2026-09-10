<?php

namespace App\Services\Sqns;

use App\Models\Integrations\Sqns\Client as SqnsClient;
use App\Services\amoCRM\Client as AmoClient;
use Illuminate\Support\Facades\Log;
use Throwable;
use Ufee\Amo\Models\Contact;

class AmoContacts
{
    public function resolve(SqnsClient $client, AmoClient $amoApi, ?int $responsibleUserId): Contact
    {
        $contact = $this->findExisting($client, $amoApi);

        if (! $contact) {
            $contact = $amoApi->service->contacts()->create();
            $contact->name = 'Клиент SQNS';

            if ($responsibleUserId) {
                $contact->responsible_user_id = $responsibleUserId;
            }
        }

        if (filled($client->name)) {
            $contact->name = $client->name;
        }

        if (filled($client->email)) {
            $contact->cf('Email')->setValue(mb_strtolower(trim((string) $client->email)));
        }

        $phone = filled($client->phone) ? $client->phone : $client->additional_phone;

        if (filled($phone)) {
            $contact->cf('Телефон')->setValue($this->phoneForStore($phone));
        }

        $contact->save();
        $client->forceFill(['contact_id' => $contact->id])->save();

        return $this->get($amoApi, (int) $contact->id) ?: $contact;
    }

    public function findExisting(SqnsClient $client, AmoClient $amoApi): ?Contact
    {
        $contact = $client->contact_id ? $this->get($amoApi, (int) $client->contact_id) : null;
        $contact ??= $this->search($client, $amoApi);

        if ($contact && (int) $client->contact_id !== (int) $contact->id) {
            $client->forceFill(['contact_id' => $contact->id])->save();
        }

        return $contact;
    }

    public function get(AmoClient $amoApi, int $id): ?Contact
    {
        try {
            $contact = $amoApi->service->contacts()->find($id);

            return $contact instanceof Contact ? $contact : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function search(SqnsClient $client, AmoClient $amoApi): ?Contact
    {
        $phone = $this->phoneSearchKey(
            filled($client->phone) ? $client->phone : $client->additional_phone,
        );
        $email = filled($client->email) ? mb_strtolower(trim((string) $client->email)) : null;
        $phoneContacts = $this->searchResult(function () use ($amoApi, $phone) {
            return $phone ? $amoApi->service->contacts()->searchByPhone($phone) : null;
        }, 'phone', $phone);
        $emailContacts = $this->searchResult(function () use ($amoApi, $email) {
            return $email ? $amoApi->service->contacts()->searchByEmail($email) : null;
        }, 'email', $email);

        if ($phone && $email) {
            $emailIds = collect($emailContacts)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $match = collect($phoneContacts)->first(fn (Contact $contact): bool => in_array((int) $contact->id, $emailIds, true));

            if ($match instanceof Contact) {
                return $match;
            }

            if ($phoneContacts !== [] && $emailContacts !== []) {
                Log::warning('SQNS contact identifiers point to different amoCRM contacts.', [
                    'sqns_client_id' => $client->client_id,
                    'phone_matches' => count($phoneContacts),
                    'email_matches' => count($emailContacts),
                ]);

                return null;
            }
        }

        return $phoneContacts[0] ?? $emailContacts[0] ?? null;
    }

    private function searchResult(callable $search, string $type, ?string $value): array
    {
        if (! $value) {
            return [];
        }

        try {
            $result = $search();

            if ($result instanceof Contact) {
                return [$result];
            }

            if (is_object($result)) {
                $result = $result->all();
            }

            return collect(is_iterable($result) ? $result : [])
                ->filter(fn (mixed $contact): bool => $contact instanceof Contact)
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::warning('SQNS amoCRM contact search failed.', [
                'type' => $type,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function phoneSearchKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (! $digits || strlen($digits) < 6) {
            return null;
        }

        return substr($digits, -10);
    }

    private function phoneForStore(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (! $digits) {
            return null;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '+7'.$digits;
        }

        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
            return '+7'.substr($digits, -10);
        }

        return str_starts_with(trim((string) $phone), '+') ? '+'.$digits : $digits;
    }
}
