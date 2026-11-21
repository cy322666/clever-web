<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Client;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\RecordEvent;
use InvalidArgumentException;

final class RecordSyncContext
{
    private static int $depth = 0;
    private static ?RecordEvent $event = null;

    public static function run(callable $operation, ?RecordEvent $event = null): mixed
    {
        $previous = self::$event;
        self::$depth++;
        self::$event = $event ?? $previous;
        try {
            return $operation();
        } finally {
            self::$event = $previous;
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    public static function record(int $id): Record
    {
        $record = Record::query()->findOrFail($id);
        if (!self::$event) {
            if (RecordEventQueue::ready() && RecordEventQueue::head($id)) {
                throw new RequestDeferredException('YClients record has ordered events; use the event queue.');
            }
            return $record;
        }
        $snapshot = self::$event->record_snapshot;
        if ((int) self::$event->record_id !== $id) {
            throw new InvalidArgumentException('YClients event record scope mismatch.');
        }
        foreach (['user_id', 'account_id', 'setting_id', 'company_id', 'record_id'] as $field) {
            if ((string) $record->{$field} !== (string) ($snapshot[$field] ?? null)) {
                throw new InvalidArgumentException('YClients event tenant scope mismatch.');
            }
        }
        // A historical snapshot is the read baseline, never a dirty update to the latest row.
        $record->setRawAttributes(array_replace($record->getAttributes(), $snapshot), true);
        $record->syncSourceSnapshot = self::$event->source_snapshot;
        return $record;
    }

    public static function client(Record $record, ?Client $client): ?Client
    {
        if (!$client || !self::$event || (int) self::$event->record_id !== (int) $record->id) {
            return $client;
        }
        $snapshot = self::$event->client_snapshot;
        if (!$snapshot || (string) ($snapshot['client_id'] ?? null) !== (string) $client->client_id) {
            return $client;
        }
        foreach (['user_id', 'account_id', 'setting_id', 'company_id'] as $field) {
            if ((string) $client->{$field} !== (string) ($snapshot[$field] ?? null)) {
                throw new InvalidArgumentException('YClients event client scope mismatch.');
            }
        }
        $client->setRawAttributes(array_replace($client->getAttributes(), $snapshot), true);
        return $client;
    }
}
