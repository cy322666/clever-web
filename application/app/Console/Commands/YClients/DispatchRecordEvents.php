<?php

namespace App\Console\Commands\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\RecordEvent;
use App\Services\YClients\RecordEventQueue;
use Illuminate\Console\Command;

class DispatchRecordEvents extends Command
{
    protected $signature = 'yc:dispatch-record-events {--limit=1000}';
    protected $description = 'Dispatch durable YClients event heads not acknowledged by the queue.';

    public function handle(): int
    {
        if (!RecordEventQueue::ready()) {
            return self::SUCCESS;
        }
        $ids = RecordEvent::query()->where('status', Record::STATUS_PENDING)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('yclients_record_events as earlier')
                ->whereColumn('earlier.record_id', 'yclients_record_events.record_id')
                ->whereColumn('earlier.id', '<', 'yclients_record_events.id')
                ->where('earlier.status', '!=', Record::STATUS_SUCCESS))
            ->where('available_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(15)))
            ->select('record_id')->distinct()->orderBy('record_id')->limit(max(1, (int) $this->option('limit')))->pluck('record_id');
        foreach ($ids as $id) {
            RecordEventQueue::dispatchHead((int) $id, true);
        }
        return self::SUCCESS;
    }
}
