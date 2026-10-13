<?php

namespace App\Workflows\Actions;

use App\Forms\Components\WorkflowValueInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Leek\FilamentWorkflows\Concerns\WorkflowAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;

class WorkflowLoopAction
{
    use WorkflowAction;

    public const MAX_ITEMS = 1000;

    public static function workflowType(): string { return 'workflow_loop'; }
    public static function workflowName(): string { return 'Цикл'; }
    public static function workflowDescription(): string { return 'Выполнить ветку для каждого элемента списка'; }
    public static function workflowIcon(): string { return 'heroicon-o-arrow-path'; }
    public static function workflowCategory(): string { return 'Управление потоком'; }
    public static function workflowDefaultConfig(): array { return ['items' => '{{ $json }}', 'mode' => 'all', 'require_return' => true]; }

    public static function workflowConfigSchema(?string $modelClass = null): array
    {
        return [
            \Filament\Forms\Components\Hidden::make('require_return')->default(true),
            WorkflowValueInput::make('items')->label('Что подать в цикл')->required()
                ->default('{{ $json }}')->placeholder('{{ $("Получить заказы").result }}'),
            Select::make('mode')->label('Сколько элементов обработать')->required()->default('all')
                ->options(['all' => 'Все элементы', 'once' => 'Один раз: только первый элемент', 'limit' => 'Указать количество']),
            TextInput::make('limit')->label('Количество проходов')->integer()->minValue(1)->maxValue(self::MAX_ITEMS)
                ->dehydrateStateUsing(static fn ($state): ?int => filled($state) ? (int) $state : null)
                ->placeholder('Например, 5')
                ->visibleJs("\$get('mode') === 'limit'")
                ->required(fn (Get $get): bool => $get('mode') === 'limit')
                ->dehydrated(fn (Get $get): bool => $get('mode') === 'limit')
                ->validatedWhenNotDehydrated(false),
        ];
    }

    public static function iterationLimit(array $config): ?int
    {
        $mode = $config['mode'] ?? 'all';
        if ($mode === 'all') return null;
        if ($mode === 'once') return 1;
        if ($mode !== 'limit') throw new \InvalidArgumentException('Выберите режим цикла.');

        $value = $config['limit'] ?? null;
        $limit = (is_int($value) || is_string($value))
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_ITEMS]])
            : false;
        if ($limit === false) throw new \InvalidArgumentException('Количество проходов должно быть целым числом от 1 до '.self::MAX_ITEMS.'.');
        return $limit;
    }

    public static function items(mixed $items): array
    {
        if (is_string($items)) {
            $json = trim($items);
            if (!str_starts_with($json, '[')) throw new \InvalidArgumentException('На вход цикла нужен список (JSON-массив), а не объект или одно значение.');
            try { $items = json_decode($json, true, 64, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new \InvalidArgumentException('На входе цикла некорректный JSON-массив.'); }
        }
        if (!is_array($items) || !array_is_list($items)) throw new \InvalidArgumentException('Выберите список на вход цикла. Например: {{ $("Получить заказы").result }}.');
        if (count($items) > self::MAX_ITEMS) throw new \InvalidArgumentException('В цикле доступно до 1000 элементов. Загружайте список частями.');
        return $items;
    }

    public function handle(array $config, ?WorkflowContext $context = null): array
    {
        try {
            $config = $context ? $context->resolve($config) : $config;
            $limit = self::iterationLimit($config);
            $items = self::items($config['items'] ?? null);
            return ['success' => true, 'output' => ['_workflow_loop' => true,
                'items' => $limit === null ? $items : array_slice($items, 0, $limit), 'input_count' => count($items)]];
        } catch (\Throwable $error) {
            return ['success' => false, 'error' => $error->getMessage()];
        }
    }
}
