<?php

namespace App\Services\Core;

use App\Models\Core\ApiRequest;
use App\Models\Integrations\YClients\Record;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ApiRequestRetention
{
    public function query(int $days, int $errorDays = 30): Builder
    {
        $query = ApiRequest::query()->where('created_at', '<', now()->subDays(max(1, $days)))
            ->where(fn (Builder $query) => $query->where('status_code', '<', 500)
                ->orWhere('created_at', '<', now()->subDays(max($days, $errorDays))));

        // Recent record webhooks can still be needed for cross-account incident review.
        $query->where(fn (Builder $query) => $query->whereNull('route_name')
            ->orWhere('route_name', '!=', 'yclients.hook')
            ->orWhere('created_at', '<', now()->subDays(max($days, $errorDays)))
            ->orWhere(fn (Builder $payload) => $payload->whereNotNull('payload->resource')
                ->where('payload->resource', '!=', 'record')));

        if (!Schema::hasTable('yclients_records')) {
            return $query;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $resourceId = $grammar->wrap('api_requests.payload->resource_id');
        $dataId = $grammar->wrap('api_requests.payload->data->id');
        $hasOutbox = Schema::hasTable('yclients_record_events');

        // An uncorrelated set avoids scanning every record for every retained request.
        $unresolved = DB::table('yclients_records as unresolved')
            ->selectRaw('CAST(unresolved.record_id AS VARCHAR)')
            ->whereNotNull('unresolved.record_id')
            ->where(function (QueryBuilder $query) use ($hasOutbox): void {
                $query->where('unresolved.status', '!=', Record::STATUS_SUCCESS)
                    ->orWhereNull('unresolved.status')->orWhereNotNull('unresolved.error_message')
                    ->orWhereNotNull('unresolved.lead_fields_replay_error')
                    ->orWhereNotNull('unresolved.mapped_fields_update_error');
                if ($hasOutbox) {
                    $query->orWhereIn('unresolved.id', fn (QueryBuilder $events) => $events->select('unfinished.record_id')
                        ->from('yclients_record_events as unfinished')
                        ->where(fn (QueryBuilder $events) => $events->where('unfinished.status', '!=', Record::STATUS_SUCCESS)
                            ->orWhereNull('unfinished.status')));
                }
            });
        if (Schema::hasColumn('yclients_records', 'lead_fields_replay_status')) {
            $unresolved->orWhere(fn (QueryBuilder $records) => $records
                ->whereNotNull('unresolved.record_id')->whereNotNull('unresolved.lead_fields_replay_status')
                ->whereNotIn('unresolved.lead_fields_replay_status', ['success', 'skipped']));
        }

        // Keep the original inputs needed to reconcile ambiguous or blocked exports.
        foreach (['payload->resource_id' => $resourceId, 'payload->data->id' => $dataId] as $column => $expression) {
            $query->where(fn (Builder $query) => $query->whereNull($column)
                ->orWhereNotIn(DB::raw('CAST('.$expression.' AS VARCHAR)'), clone $unresolved));
        }

        return $query;
    }

    public function count(int $days, int $errorDays = 30): int
    {
        return Schema::hasTable('api_requests') ? $this->query($days, $errorDays)->count() : 0;
    }

    public function prune(int $days, int $errorDays = 30, int $chunk = 500): int
    {
        if (!Schema::hasTable('api_requests')) {
            return 0;
        }
        $deleted = 0;
        $lastId = 0;
        while (true) {
            $ids = $this->query($days, $errorDays)->where('id', '>', $lastId)
                ->orderBy('id')->limit(max(1, $chunk))->pluck('id');
            if ($ids->isEmpty()) {
                return $deleted;
            }
            $deleted += $this->query($days, $errorDays)->whereKey($ids->all())->delete();
            $lastId = (int) $ids->last();
        }
    }
}
