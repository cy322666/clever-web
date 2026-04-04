<?php

namespace App\Filament\App\Widgets;

use App\Support\AppStats\AppStatsAggregator;
use Filament\Widgets\ChartWidget;

class AppStatsInstallTrendChart extends ChartWidget
{
    protected int|string|array $columnSpan = ['md' => 1, 'xl' => 1];

    protected ?string $heading = 'Динамика установок';

    protected ?string $description = 'Новые установки по дням за последние 30 дней';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $trend = AppStatsAggregator::installsTrend(30);

        return [
            'labels' => $trend['labels'],
            'datasets' => [
                [
                    'label' => 'Установки',
                    'data' => $trend['data'],
                    'fill' => true,
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.15)',
                    'tension' => 0.25,
                ],
            ],
        ];
    }

    protected function getOptions(): ?array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}
