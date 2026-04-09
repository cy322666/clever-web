<?php

namespace App\Filament\App\Widgets;

use App\Support\AppStats\AppStatsAggregator;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AppStatsOverview extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Ключевые метрики';

    protected ?string $description = 'Общая картина по установленным приложениям';

    protected function getStats(): array
    {
        $totals = AppStatsAggregator::totals();

        $activationRate = $totals['integrations_installed'] > 0
            ? round(($totals['integrations_active'] / $totals['integrations_installed']) * 100, 1)
            : 0;

        return [
            Stat::make('Установки', $this->formatNumber($totals['integrations_installed']))
                ->description('Уникальных приложений: ' . $totals['apps_total'])
                ->descriptionIcon('heroicon-o-squares-2x2')
                ->chart($totals['installs_trend_14'])
                ->color('info'),

            Stat::make('Активные интеграции', $this->formatNumber($totals['integrations_active']))
                ->description('Активных клиентов: ' . $this->formatNumber($totals['clients_with_active']))
                ->descriptionIcon('heroicon-o-bolt')
                ->color('success'),

            Stat::make('Просроченные', $this->formatNumber($totals['integrations_expired']))
                ->description('Нужно продлить/переподключить')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color('danger'),

            Stat::make('Истекают за 7 дней', $this->formatNumber($totals['integrations_expiring_soon']))
                ->descriptionIcon('heroicon-o-clock')
                ->color('warning'),

            Stat::make('Конверсия в активные', $activationRate . '%')
                ->description('От всех установленных интеграций')
                ->descriptionIcon('heroicon-o-chart-bar-square')
                ->color($activationRate >= 75 ? 'success' : ($activationRate >= 50 ? 'warning' : 'danger')),

            Stat::make('Транзакции', $this->formatNumber($totals['transactions_total']))
                ->description('Суммарно по всем приложениям')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color('gray'),
        ];
    }

    private function formatNumber(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }
}
