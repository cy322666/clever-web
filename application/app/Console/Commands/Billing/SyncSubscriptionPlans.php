<?php

namespace App\Console\Commands\Billing;

use App\Models\App;
use App\Models\Billing\SubscriptionPlan;
use Illuminate\Console\Command;
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
        '3_month' => ['label' => '3 месяца', 'days' => 90, 'sort' => 15],
        '6_month' => ['label' => '6 месяцев', 'days' => 180, 'sort' => 20],
        '12_month' => ['label' => '12 месяцев', 'days' => 365, 'sort' => 30],
        '24_month' => ['label' => '24 месяца', 'days' => 730, 'sort' => 40],
    ];

    protected $signature = 'subscriptions:sync-plans
        {--widget= : Синхронизировать тарифы только выбранного виджета}
        {--dry-run : Только показать изменения без сохранения}';

    protected $description = 'Заполняет тарифы виджетов из прайсов моделей настроек';

    public function handle(): int
    {
        $dryRun = (bool)$this->option('dry-run');
        $created = 0;
        $updated = 0;
        $deactivated = 0;
        $definitions = App::definitions();
        $selectedWidget = $this->option('widget');
        if ($selectedWidget !== null) {
            if (!$definitions->has($selectedWidget)) {
                $this->error('Неизвестный виджет: '.$selectedWidget);

                return self::FAILURE;
            }
            $definitions = $definitions->only([$selectedWidget]);
        }

        foreach ($definitions as $widget => $definition) {
            $cost = $this->resolveCost((string)($definition['resource'] ?? ''));
            $title = App::getTitle($widget, $definition['resource'] ?? null);
            $description = App::getTooltipText($widget);

            foreach (self::PERIODS as $periodKey => $period) {
                if (!array_key_exists($periodKey, $cost)) {
                    continue;
                }
                $priceLabel = (string)$cost[$periodKey];
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

            // Retain obsolete plans for existing subscriptions and payment history.
            $retiredSlugs = array_map(
                fn (string $period): string => $widget.'-'.str_replace('_', '-', $period),
                array_keys(array_diff_key(self::PERIODS, $cost)),
            );
            $retired = SubscriptionPlan::query()->where('widget', $widget)
                ->whereIn('slug', $retiredSlugs)->where('is_active', true);
            if ($dryRun) {
                foreach ($retired->pluck('slug') as $slug) {
                    $this->line('deactivate '.$slug);
                }
            } else {
                $deactivated += $retired->update(['is_active' => false]);
            }
        }

        $this->info('Синхронизация тарифов завершена.');
        $this->line('Создано: ' . $created);
        $this->line('Обновлено: ' . $updated);
        $this->line('Отключено: ' . $deactivated);

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
                    return $cost;
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
