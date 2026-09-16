<?php

namespace App\Workflows\Triggers;

use Cron\CronExpression;
use DateTimeInterface;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

class ScheduleTrigger extends \Leek\FilamentWorkflows\Triggers\ScheduleTrigger
{
    public static function configSchema(): array
    {
        return [
            Select::make('timezone')->label('Часовой пояс')->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))->searchable()->default(config('app.timezone'))->required(),
            Repeater::make('rules')->label('Расписание')->minItems(1)->maxItems(20)->defaultItems(1)->reorderable(false)->addActionLabel('Добавить правило')->schema([
                Select::make('frequency')->label('Повторять')->options(['minutes' => 'Каждые N минут', 'hourly' => 'Каждый час', 'daily' => 'Каждый день', 'weekly' => 'Каждую неделю', 'monthly' => 'Каждый месяц', 'custom' => 'Cron'])->default('daily')->live()->required(),
                TextInput::make('interval')->label('Интервал, минут')->numeric()->minValue(1)->maxValue(59)->default(5)->required()->visible(fn (Get $get) => $get('frequency') === 'minutes'),
                TextInput::make('time')->label('Время')->type('time')->default('09:00')->required()->visible(fn (Get $get) => in_array($get('frequency'), ['daily', 'weekly', 'monthly'])),
                Select::make('day_of_week')->label('День недели')->options([1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 0 => 'Воскресенье'])->default(1)->required()->visible(fn (Get $get) => $get('frequency') === 'weekly'),
                TextInput::make('day_of_month')->label('День месяца')->numeric()->minValue(1)->maxValue(31)->default(1)->required()->visible(fn (Get $get) => $get('frequency') === 'monthly'),
                TextInput::make('cron_expression')->label('Cron')->placeholder('0 9 * * 1-5')->required()->rules([fn () => function ($attribute, $value, $fail) {
                    if (! CronExpression::isValidExpression($value)) {
                        $fail('Неверное выражение cron.');
                    }
                }])->visible(fn (Get $get) => $get('frequency') === 'custom'),
            ]),
        ];
    }

    public static function defaultConfig(): array
    {
        return ['timezone' => config('app.timezone'), 'rules' => [['frequency' => 'daily', 'time' => '09:00']]];
    }

    public static function normalize(array $config): array
    {
        return ['timezone' => $config['timezone'] ?? config('app.timezone'), 'rules' => array_values($config['rules'] ?? [$config ?: ['frequency' => 'daily', 'time' => '09:00']])];
    }

    public function buildCronExpression(array $config): ?string
    {
        if (isset($config['rules'])) {
            $config = array_values($config['rules'])[0] ?? [];
        }
        if (($config['frequency'] ?? '') === 'minutes') {
            return '*/'.max(1, min(59, (int) ($config['interval'] ?? 5))).' * * * *';
        }

        return parent::buildCronExpression($config);
    }

    public function shouldTrigger(array $config, mixed $subject, array $context = []): bool
    {
        $config = self::normalize($config);
        foreach ($config['rules'] as $rule) {
            $expression = $this->buildCronExpression($rule);
            if ($expression && (new CronExpression($expression))->isDue($context['check_time'] ?? now(), $config['timezone'])) {
                return true;
            }
        }

        return false;
    }

    public function getNextRunTime(array $config): ?DateTimeInterface
    {
        $config = self::normalize($config);
        $dates = [];
        foreach ($config['rules'] as $rule) {
            try {
                $dates[] = (new CronExpression($this->buildCronExpression($rule)))->getNextRunDate('now', 0, false, $config['timezone']);
            } catch (\Throwable) {
            }
        }
        sort($dates);

        return $dates[0] ?? null;
    }

    public static function getConfiguredDescription(array $config): string
    {
        return count(self::normalize($config)['rules']).' правил · '.($config['timezone'] ?? config('app.timezone'));
    }

    public function validateConfig(array $config): array
    {
        $normalized = self::normalize($config);
        if (! $normalized['rules'] || count($normalized['rules']) > 20 || ! in_array($normalized['timezone'], timezone_identifiers_list(), true)) {
            return ['valid' => false, 'errors' => ['Проверьте правила и часовой пояс.']];
        }
        foreach ($normalized['rules'] as $rule) {
            try {
                if (! CronExpression::isValidExpression($this->buildCronExpression($rule) ?? '')) {
                    return ['valid' => false, 'errors' => ['Неверное правило расписания.']];
                }
            } catch (\Throwable) {
                return ['valid' => false, 'errors' => ['Неверное правило расписания.']];
            }
        }

        return ['valid' => true, 'errors' => []];
    }
}
