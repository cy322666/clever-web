<?php

namespace App\Services\YClients;

use App\Jobs\YClients\RecordSend;
use App\Models\Integrations\YClients\Client;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\RecordEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class RecordEventQueue
{
    public static function ready(): bool
    {
        return Schema::hasTable('yclients_record_events');
    }

    public static function append(Record $record, ?Client $client, array $payload, bool $createNote): RecordEvent
    {
        $previous = RecordEvent::where('record_id', $record->id)->orderByDesc('id')->first();
        $source = array_replace($previous?->source_snapshot ?? [], $payload['data'] ?? []);
        if (is_array($payload['data']['client'] ?? null)) {
            $previousClient = $previous?->source_snapshot['client'] ?? [];
            $source['client'] = array_replace(
                is_array($previousClient) && (string) ($previousClient['id'] ?? '') === (string) ($payload['data']['client']['id'] ?? '')
                    ? $previousClient : [],
                $payload['data']['client'],
            );
        }
        $event = RecordEvent::create([
            'record_id' => $record->id,
            'payload' => $payload,
            'source_snapshot' => $source,
            'record_snapshot' => array_diff_key($record->getAttributes(), array_flip([
                'id', 'lead_id', 'status', 'error_message', 'created_at', 'updated_at',
                'lead_fields_replay_status', 'lead_fields_replayed_at', 'lead_fields_replay_error',
                'mapped_fields_updated_at', 'mapped_fields_update_error', 'send',
            ])),
            'client_snapshot' => $client ? array_diff_key($client->getAttributes(), array_flip([
                'id', 'contact_id', 'created_at', 'updated_at',
            ])) : null,
            'create_note' => $createNote,
            'status' => Record::STATUS_PENDING,
            'available_at' => now(),
        ]);
        self::syncState($record);
        return $event;
    }

    public static function head(int $recordId): ?RecordEvent
    {
        return RecordEvent::where('record_id', $recordId)->where('status', '!=', Record::STATUS_SUCCESS)
            ->orderBy('id')->first();
    }

    public static function dispatchHead(int $recordId, bool $recover = false): bool
    {
        if (!self::ready()) {
            return false;
        }
        return DB::transaction(function () use ($recordId, $recover): bool {
            $record = Record::query()->lockForUpdate()->find($recordId);
            $event = $record ? self::head($recordId) : null;
            if (!$event || $event->status !== Record::STATUS_PENDING || !$record->account || !$record->setting) {
                return false;
            }
            if ($event->dispatched_at && (!$recover || $event->dispatched_at->greaterThan(now()->subMinutes(15))
                    || $event->available_at?->isFuture())) {
                return false;
            }
            $event->forceFill(['dispatched_at' => now()])->save();
            DB::afterCommit(function () use ($record, $event): void {
                try {
                    RecordSend::dispatch($record, $record->account, $record->setting, $event->create_note, (int) $event->id)
                        ->delay($event->available_at ?? now());
                } catch (Throwable $error) {
                    RecordEvent::whereKey($event->id)->update(['dispatched_at' => null]);
                    Log::warning('YClients event retained in outbox after dispatch failed.', [
                        'event_id' => $event->id, 'exception' => $error::class,
                    ]);
                }
            });
            return true;
        });
    }

    public static function finish(RecordEvent $event, string $status, ?string $error = null): void
    {
        DB::transaction(function () use ($event, $status, $error): void {
            $record = Record::query()->lockForUpdate()->findOrFail($event->record_id);
            $event->forceFill(['status' => $status, 'error_message' => $error,
                'processed_at' => $status === Record::STATUS_SUCCESS ? now() : null])->save();
            self::syncState($record);
        });
    }

    public static function defer(RecordEvent $event, int $seconds): void
    {
        $event->forceFill(['status' => Record::STATUS_PENDING, 'error_message' => null,
            'available_at' => now()->addSeconds($seconds), 'dispatched_at' => now()])->save();
    }

    public static function retryFailed(int $recordId): void
    {
        if (!self::ready()) {
            return;
        }
        DB::transaction(function () use ($recordId): void {
            $record = Record::query()->lockForUpdate()->findOrFail($recordId);
            $event = self::head($recordId);
            if (!$event) {
                return;
            }
            if ($event?->status === Record::STATUS_FAILED) {
                $event->forceFill(['status' => Record::STATUS_PENDING, 'error_message' => null,
                    'available_at' => now(), 'dispatched_at' => null])->save();
                \Illuminate\Support\Facades\Cache::store(config('cache.yclients_lock_store', 'database'))
                    ->forget('yclients:record-transport:event:'.$event->id);
            }
            self::syncState($record);
        });
    }

    public static function syncState(Record $record): void
    {
        $head = self::head((int) $record->id);
        $record->forceFill(['status' => $head?->status ?? Record::STATUS_SUCCESS,
            'error_message' => $head?->status === Record::STATUS_FAILED ? $head->error_message : null])->save();
    }
}
