<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Client;
use App\Models\Integrations\YClients\Record;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Durable write receipts. A lost POST response must never be replayed blindly. */
final class EntityCreateGuard
{
    public function __construct(
        private string $key,
        private int $accountId,
        private string $entity,
        private ?string $identityHash = null,
    ) {}

    public static function ready(): bool
    {
        return Schema::hasTable('yclients_create_receipts');
    }

    public static function forLead(Record $record): self
    {
        return new self(hash('sha256', implode('|', ['lead', $record->account_id, $record->company_id, $record->record_id])),
            (int) $record->account_id, 'lead');
    }

    public static function forNote(Record $record): self
    {
        return new self(hash('sha256', implode('|', ['note', $record->account_id, $record->company_id, $record->record_id])),
            (int) $record->account_id, 'note');
    }

    public function claim(): bool
    {
        $values = [
            'key' => $this->key, 'account_id' => $this->accountId, 'entity' => $this->entity,
            'started_at' => time(), 'identity_hash' => $this->identityHash,
            'rejected' => false,
        ];
        return DB::table('yclients_create_receipts')->insertOrIgnore($values) > 0
            || DB::table('yclients_create_receipts')->where('key', $this->key)
                ->where('rejected', true)->whereNull('remote_id')->update($values) > 0;
    }

    public static function forContact(Client $client): self
    {
        return new self(hash('sha256', implode('|', ['contact', $client->account_id, $client->company_id, $client->client_id])),
            (int) $client->account_id, 'contact', hash('sha256', json_encode([
                Contacts::clearPhone($client->phone), mb_strtolower(trim((string) $client->email)),
            ])));
    }

    public function exists(): bool
    {
        return DB::table('yclients_create_receipts')->where('key', $this->key)->where('rejected', false)->exists();
    }

    public function recover(callable $findById, callable $reconcile): object
    {
        $receipt = DB::table('yclients_create_receipts')->where('key', $this->key)->first();
        if (!$receipt || ($receipt->identity_hash !== null && !hash_equals($receipt->identity_hash, (string) $this->identityHash))) {
            throw new UnconfirmedCreateException($this->entity);
        }

        $entity = $receipt->remote_id ? $findById((int) $receipt->remote_id) : $reconcile((int) $receipt->started_at);
        if (!is_object($entity) || (int) $entity->id <= 0 || ($receipt->remote_id && (int) $entity->id !== (int) $receipt->remote_id)) {
            throw new UnconfirmedCreateException($this->entity);
        }

        $this->acknowledge((int) $entity->id);

        return $entity;
    }

    public function create(callable $create): object
    {
        // Atomic across duplicate local rows/jobs and survives process death or cache clearing.
        if (!$this->claim()) {
            throw new UnconfirmedCreateException($this->entity);
        }

        try {
            $entity = $create();
            if (!is_object($entity) || (int) $entity->id <= 0) {
                throw new UnconfirmedCreateException($this->entity);
            }

            $this->acknowledge((int) $entity->id);
            return $entity;
        } catch (Throwable $exception) {
            // Only an explicit rejection permits a new POST after configuration/auth is fixed.
            // Never infer rejection from a timeout, 5xx, missing JSON or missing search result.
            if (in_array((int) $exception->getCode(), [400, 401, 403, 404, 422, 429], true)
                || preg_match('/Invalid API response \(non JSON\), code: (?:400|401|403|404|422|429)(?=\s|$)/', $exception->getMessage())) {
                DB::table('yclients_create_receipts')->where('key', $this->key)->whereNull('remote_id')
                    ->update(['rejected' => true]);
            }
            if (AmoTransportRetry::isTransportError($exception)) {
                throw new UnconfirmedCreateException($this->entity, $exception);
            }

            // Keep the receipt even for an unexpected failure after the remote write.
            throw $exception;
        }
    }

    private function acknowledge(int $id): void
    {
        DB::table('yclients_create_receipts')->where('key', $this->key)->update(['remote_id' => $id]);
    }
}
