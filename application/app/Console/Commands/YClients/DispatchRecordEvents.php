<?php

namespace App\Console\Commands\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\RecordEvent;
use App\Services\YClients\RecordEventQueue;
use App\Services\YClients\RecordEventQueueSnapshot;
use Illuminate\Console\Command;

class DispatchRecordEvents extends Command
{
    protected $signature = 'yc:dispatch-record-events {--limit=1000} {--dry-run : Count recoverable events without dispatching}';
    protected $description = 'Dispatch durable YClients event heads not acknowledged by the queue.';

    public function handle(RecordEventQueueSnapshot $queue): int
    {
        if (!RecordEventQueue::ready()) {
            return self::SUCCESS;
        }
        $due = RecordEvent::query()->where('status', Record::STATUS_PENDING)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('yclients_record_events as earlier')
                ->whereColumn('earlier.record_id', 'yclients_record_events.record_id')
                ->whereColumn('earlier.id', '<', 'yclients_record_events.id')
                ->where('earlier.status', '!=', Record::STATUS_SUCCESS))
            ->where('available_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(15)));

        if (!(clone $due)->exists()) {
            if ($this->option('dry-run')) {
                $this->info('Recoverable YClients events: 0');
            }
            return self::SUCCESS;
        }

        $snapshot = $queue->read();
        if ($snapshot === null) {
            $this->warn('YClients recovery deferred: queue snapshot unavailable.');
            return self::SUCCESS;
        }

        $events = $due->whereIntegerNotInRaw('id', array_keys($snapshot['events']))
            ->whereIntegerNotInRaw('record_id', array_keys($snapshot['legacy_records']))
            ->orderBy('record_id')->limit(max(1, (int) $this->option('limit')))->get(['id', 'record_id']);

        if ($this->option('dry-run')) {
            $this->info('Recoverable YClients events: '.$events->count());
            return self::SUCCESS;
        }

        foreach ($events as $event) {
            RecordEventQueue::dispatchHead((int) $event->record_id, recoverEventId: (int) $event->id);
        }
        return self::SUCCESS;
    }
}
