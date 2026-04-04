<?php

namespace App\Support\AppStats;

use App\Models\App;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AppStatsAggregator
{
    private static ?Collection $apps = null;

    private static ?Collection $rows = null;

    public static function totals(): array
    {
        $today = now()->startOfDay();
        $allApps = self::apps();
        $rows = self::perApp();

        $installedApps = $allApps->filter(fn(App $app): bool => (int)$app->status !== App::STATE_CREATED);
        $activeApps = $allApps->filter(fn(App $app): bool => self::isActiveNow($app, $today));
        $expiredApps = $allApps->filter(fn(App $app): bool => self::isExpired($app, $today));

        $expiringSoon = $activeApps->filter(function (App $app) use ($today): bool {
            if (blank($app->expires_tariff_at)) {
                return false;
            }

            $expiresAt = Carbon::parse($app->expires_tariff_at)->startOfDay();

            return $expiresAt->betweenIncluded($today, $today->copy()->addDays(7));
        });

        return [
            'apps_total' => $rows->count(),
            'integrations_installed' => $installedApps->count(),
            'integrations_active' => $activeApps->count(),
            'integrations_expired' => $expiredApps->count(),
            'integrations_expiring_soon' => $expiringSoon->count(),
            'clients_with_installed' => $installedApps->pluck('user_id')->unique()->count(),
            'clients_with_active' => $activeApps->pluck('user_id')->unique()->count(),
            'transactions_total' => (int)$rows->sum('count_transactions'),
            'installs_trend_14' => self::installsTrend(14)['data'],
        ];
    }

    private static function apps(): Collection
    {
        if (self::$apps !== null) {
            return self::$apps;
        }

        self::$apps = App::query()
            ->select([
                'id',
                'name',
                'resource_name',
                'status',
                'expires_tariff_at',
                'installed_at',
                'created_at',
                'user_id',
            ])
            ->get();

        return self::$apps;
    }

    public static function perApp(): Collection
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        $today = now()->startOfDay();

        self::$rows = self::apps()
            ->groupBy('name')
            ->map(function (Collection $items, string $name) use ($today): array {
                /** @var App|null $sample */
                $sample = $items->first();

                $installedItems = $items->filter(fn(App $app): bool => (int)$app->status !== App::STATE_CREATED);
                $activeItems = $items->filter(fn(App $app): bool => self::isActiveNow($app, $today));
                $expiredItems = $items->filter(fn(App $app): bool => self::isExpired($app, $today));

                $lastInstallAt = $installedItems
                    ->map(fn(App $app): ?Carbon => self::resolveInstallDate($app))
                    ->filter()
                    ->sortDesc()
                    ->first();

                $countInstalls = $installedItems->count();
                $countActive = $activeItems->count();

                return [
                    'key' => $name,
                    'name' => $name,
                    'count_installs' => $countInstalls,
                    'count_active' => $countActive,
                    'count_expired' => $expiredItems->count(),
                    'count_inactive' => $items->where('status', App::STATE_INACTIVE)->count(),
                    'count_created' => $items->where('status', App::STATE_CREATED)->count(),
                    'count_transactions' => self::resolveTransactionsCount($sample?->resource_name),
                    'count_users_active' => $activeItems->pluck('user_id')->unique()->count(),
                    'count_users_installed' => $installedItems->pluck('user_id')->unique()->count(),
                    'activation_rate' => $countInstalls > 0
                        ? round(($countActive / $countInstalls) * 100, 1)
                        : 0,
                    'last_install_at' => $lastInstallAt,
                ];
            })
            ->sortByDesc('count_active')
            ->values();

        return self::$rows;
    }

    private static function isActiveNow(App $app, Carbon $today): bool
    {
        return (int)$app->status === App::STATE_ACTIVE && !self::isExpired($app, $today);
    }

    private static function isExpired(App $app, Carbon $today): bool
    {
        if ((int)$app->status === App::STATE_EXPIRES) {
            return true;
        }

        if ((int)$app->status !== App::STATE_ACTIVE) {
            return false;
        }

        if (blank($app->expires_tariff_at)) {
            return false;
        }

        return Carbon::parse($app->expires_tariff_at)->startOfDay()->lt($today);
    }

    private static function resolveInstallDate(App $app): ?Carbon
    {
        $date = $app->installed_at ?? $app->created_at;

        if (blank($date)) {
            return null;
        }

        if ($date instanceof Carbon) {
            return $date;
        }

        return Carbon::parse($date);
    }

    private static function resolveTransactionsCount(?string $resourceClass): int
    {
        if (!is_string($resourceClass) || !class_exists($resourceClass) || !method_exists(
                $resourceClass,
                'getTransactions'
            )) {
            return 0;
        }

        try {
            $transactions = $resourceClass::getTransactions();
        } catch (\Throwable) {
            return 0;
        }

        if (is_numeric($transactions)) {
            return (int)$transactions;
        }

        return (int)preg_replace('/[^0-9]/', '', (string)$transactions);
    }

    public static function installsTrend(int $days = 30): array
    {
        $from = now()->startOfDay()->subDays($days - 1);
        $to = now()->endOfDay();

        $dateCounters = self::apps()
            ->filter(fn(App $app): bool => (int)$app->status !== App::STATE_CREATED)
            ->map(fn(App $app): ?Carbon => self::resolveInstallDate($app))
            ->filter(fn(?CarbonInterface $date): bool => $date !== null)
            ->filter(fn(Carbon $date): bool => $date->between($from, $to))
            ->countBy(fn(Carbon $date): string => $date->toDateString());

        $labels = [];
        $data = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $labels[] = $day->format('d.m');
            $data[] = (int)($dateCounters[$day->toDateString()] ?? 0);
        }

        return [
            'labels' => $labels,
            'data' => $data,
        ];
    }

    public static function statusDistribution(): array
    {
        $today = now()->startOfDay();

        $active = 0;
        $inactive = 0;
        $created = 0;
        $expired = 0;

        foreach (self::apps() as $app) {
            if (self::isExpired($app, $today)) {
                $expired++;

                continue;
            }

            if ((int)$app->status === App::STATE_ACTIVE) {
                $active++;

                continue;
            }

            if ((int)$app->status === App::STATE_INACTIVE) {
                $inactive++;

                continue;
            }

            $created++;
        }

        return [
            'active' => $active,
            'inactive' => $inactive,
            'expired' => $expired,
            'created' => $created,
        ];
    }
}
