<?php

namespace App\Services\Core;

use App\Models\Integrations\Distribution\Transaction;
use App\Models\Integrations\Sqns\Visit as SqnsVisit;
use App\Models\Integrations\Tilda\Form;
use App\Models\Integrations\Vetmanager\Visit as VetmanagerVisit;
use App\Models\Integrations\YClients\Record;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class IntegrationHistoryPruner
{
    public const INTEGRATIONS = ['tilda', 'distribution', 'yclients', 'sqns', 'vetmanager'];

    public function query(string $integration, int $days): Builder
    {
        $cutoff = now()->subDays(max(1, $days));

        if ($integration === 'yclients') {
            return Record::query()->prunableOlderThan($cutoff);
        }

        [$model, $success, $dateColumn] = match ($integration) {
            'tilda' => [Form::class, true, null],
            'distribution' => [Transaction::class, true, null],
            'sqns' => [SqnsVisit::class, SqnsVisit::STATUS_SUCCESS, 'datetime'],
            'vetmanager' => [VetmanagerVisit::class, VetmanagerVisit::STATUS_SUCCESS, 'admission_date'],
            default => throw new InvalidArgumentException('Unknown integration history: '.$integration),
        };

        $query = $model::query()
            ->where('status', $success)
            ->where('created_at', '<', $cutoff)
            ->where(fn (Builder $query) => $query->whereNull('updated_at')->orWhere('updated_at', '<', $cutoff));

        if ($dateColumn !== null) {
            $query->whereNull('error_message')
                ->where(fn (Builder $query) => $query->whereNull($dateColumn)->orWhere($dateColumn, '<', $cutoff));
        }

        return $query;
    }

    public function count(string $integration, int $days): int
    {
        $query = $this->query($integration, $days);

        return Schema::hasTable($query->getModel()->getTable()) ? $query->count() : 0;
    }

    public function prune(string $integration, int $days, int $chunk = 500): int
    {
        $query = $this->query($integration, $days);

        if (!Schema::hasTable($query->getModel()->getTable())) {
            return 0;
        }

        $deleted = 0;
        $lastId = 0;

        do {
            $ids = $this->query($integration, $days)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(max(1, $chunk))
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // Recheck eligibility in DELETE, in case a webhook updated a selected row.
            $deleted += $this->query($integration, $days)->whereKey($ids->all())->delete();
            $lastId = (int) $ids->last();
        } while (true);

        return $deleted;
    }
}
