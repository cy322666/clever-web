<?php

namespace App\Console\Commands\Billing;

use App\Models\App;
use App\Models\Billing\SubscriptionPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class SyncSubscriptionPlans extends Command
{
    private const DEFAULT_COST = [
        '1_month' => '2 990 ₽',
        '6_month' => '14 900 ₽',
        '12_month' => '24 900 ₽',
    ];

    private const PERIODS = [
        '1_month' => ['label' => '1 месяц', 'days' => 30, 'sort' => 10],
        '6_month' => ['label' => '6 месяцев', 'days' => 180, 'sort' => 20],
        '12_month' => ['label' => '12 месяцев', 'days' => 365, 'sort' => 30],
    ];

    protected $signature = 'subscriptions:sync-plans {--dry-run : Только показать, что будет создано или обновлено}';

    protected $description = 'Заполняет тарифы виджетов из прайсов моделей настроек';

    public function handle(): int
    {
        $dryRun = (bool)$this->option('dry-run');
        $created = 0;
        $updated = 0;

        foreach (App::definitions() as $widget => $definition) {
            $cost = $this->resolveCost((string)($definition['resource'] ?? ''));
            $title = App::getTitle($widget, $definition['resource'] ?? null);
            $description = App::getTooltipText($widget);

            foreach (self::PERIODS as $periodKey => $period) {
                $priceLabel = (string)($cost[$periodKey] ?? self::DEFAULT_COST[$periodKey]);
                $slug = $widget . '-' . str_replace('_', '-', $periodKey);

                $payload = [
                    'widget' => $widget,
                    'name' => $title . ' · ' . $period['label'],
                    'description' => $description !== '' ? $description : null,
                    'price_label' => $priceLabel,
                    'price_rub' => $this->parseRubles($priceLabel),
                    'period_days' => $period['days'],
                    'features' => array_values(array_filter([
                        $description,
                    ])),
                    'limits' => [
                        'widget' => $widget,
                        'period' => $periodKey,
                    ],
                    'is_active' => true,
                    'sort_order' => ($this->widgetSort($widget) * 100) + $period['sort'],
                ];

                $existing = SubscriptionPlan::query()->where('slug', $slug)->first();

                if ($dryRun) {
                    $this->line(($existing ? 'update ' : 'create ') . $slug . ' — ' . $payload['price_label']);
                    continue;
                }

                SubscriptionPlan::query()->updateOrCreate(['slug' => $slug], $payload);

                $existing ? $updated++ : $created++;
            }
        }

        $this->info('Синхронизация тарифов завершена.');
        $this->line('Создано: ' . $created);
        $this->line('Обновлено: ' . $updated);

        return self::SUCCESS;
    }

    private function resolveCost(string $resourceClass): array
    {
        if ($resourceClass === '' || !class_exists($resourceClass)) {
            return self::DEFAULT_COST;
        }

        try {
            $modelClass = $resourceClass::getModel();

            if (is_string($modelClass) && class_exists($modelClass) && property_exists($modelClass, 'cost')) {
                $cost = $modelClass::$cost;

                if (is_array($cost) && $cost !== []) {
                    return array_replace(self::DEFAULT_COST, $cost);
                }
            }
        } catch (Throwable) {
            // Ниже используем стандартный прайс.
        }

        return self::DEFAULT_COST;
    }

    private function parseRubles(string $value): ?int
    {
        $digits = preg_replace('/\D+/', '', $value);

        return $digits !== '' ? (int)$digits : null;
    }

    private function widgetSort(string $widget): int
    {
        $names = array_values(App::definitionNames());
        $index = array_search($widget, $names, true);

        return $index === false ? 999 : ((int)$index + 1);
    }
}
