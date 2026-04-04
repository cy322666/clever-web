<?php

namespace App\Filament\App\Widgets;

use App\Support\AppStats\AppStatsAggregator;
use Filament\Widgets\ChartWidget;

class AppStatsStatusChart extends ChartWidget
{
    protected int|string|array $columnSpan = ['md' => 1, 'xl' => 1];

    protected ?string $heading = 'Распределение статусов';

    protected ?string $description = 'Текущая структура по состояниям интеграций';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $distribution = AppStatsAggregator::statusDistribution();

        return [
            'labels' => ['Активные', 'Неактивные', 'Просроченные', 'Черновики'],
            'datasets' => [
                [
                    'data' => [
                        $distribution['active'],
                        $distribution['inactive'],
                        $distribution['expired'],
                        $distribution['created'],
                    ],
                    'backgroundColor' => [
                        'rgb(34, 197, 94)',
                        'rgb(245, 158, 11)',
                        'rgb(239, 68, 68)',
                        'rgb(156, 163, 175)',
                    ],
                    'hoverOffset' => 8,
                ],
            ],
        ];
    }

    protected function getOptions(): ?array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
        ];
    }
}
