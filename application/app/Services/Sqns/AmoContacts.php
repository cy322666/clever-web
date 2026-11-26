<?php

namespace App\Services\Sqns;

use App\Models\Integrations\Sqns\Client as SqnsClient;
use App\Services\amoCRM\Client as AmoClient;
use Illuminate\Support\Facades\Log;
use Throwable;
use Ufee\Amo\Models\Contact;

class AmoContacts
{
    /**
     * Находит или создаёт контакт и сохраняет связь с клиентом SQNS.
     */
    public function resolve(SqnsClient $client, AmoClient $amoApi, ?int $responsibleUserId): Contact
    {
        $contact = $this->findExisting($client, $amoApi);

        if (! $contact) {
            $contact = $amoApi->service->contacts()->create();
            $contact->name = $client->name;

            if ($responsibleUserId)
                $contact->responsible_user_id = $responsibleUserId;
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

        return $this->get($amoApi, (int) $contact->id);
    }

    /**
     * Проверяет сохранённый ID контакта, затем ищет по телефону или email.
     */
    private function findExisting(SqnsClient $client, AmoClient $amoApi): ?Contact
    {
        $contact = $client->contact_id ? $this->get($amoApi, (int) $client->contact_id) : null;
        $contact ??= $this->search($client, $amoApi);

        if ($contact && (int) $client->contact_id !== (int) $contact->id) {
            $client->forceFill(['contact_id' => $contact->id])->save();
        }

        return $contact;
    }

    /**
     * Загружает контакт по ID, при ошибке возвращает null.
     */
    public function get(AmoClient $amoApi, int $id): ?Contact
    {
        try {
            $contact = $amoApi->service->contacts()->find($id);

            return $contact instanceof Contact ? $contact : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Ищет по телефону, затем по email, если телефон не дал совпадений.
     */
    private function search(SqnsClient $client, AmoClient $amoApi): ?Contact
    {
        $phone = $this->phoneSearchKey(
            filled($client->phone) ? $client->phone : $client->additional_phone,
        );
        if ($phone !== null) {
            $contact = $this->firstContact(
                fn () => $amoApi->service->contacts()->searchByPhone($phone),
                'phone',
            );

            if ($contact !== null) {
                return $contact;
            }
        }

        $email = mb_strtolower(trim((string) $client->email));

        return $email !== ''
            ? $this->firstContact(fn () => $amoApi->service->contacts()->searchByEmail($email), 'email')
            : null;
    }

    /**
     * Возвращает первое совпадение и передаёт ошибку поиска в очередь.
     */
    private function firstContact(callable $search, string $type): ?Contact
    {
        try {
            $result = $search();

            if ($result instanceof Contact) {
                return $result;
            }

            if (is_object($result)) {
                $result = $result->all();
            }

            return collect(is_iterable($result) ? $result : [])
                ->first(fn (mixed $contact): bool => $contact instanceof Contact);
        } catch (Throwable $exception) {
            Log::warning('SQNS amoCRM contact search failed.', [
                'type' => $type,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Выделяет цифры номера для поиска контакта.
     */
    private function phoneSearchKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (! $digits || strlen($digits) < 6) {
            return null;
        }

        return substr($digits, -10);
    }

    /**
     * Приводит телефон к формату записи в amoCRM.
     */
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
