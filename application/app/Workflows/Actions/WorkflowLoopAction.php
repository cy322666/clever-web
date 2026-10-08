<?php

namespace App\Workflows\Actions;

use App\Forms\Components\WorkflowValueInput;
use Filament\Forms\Components\Select;
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
    public static function workflowDefaultConfig(): array { return ['items' => '{{ $json }}', 'mode' => 'all']; }

    public static function workflowConfigSchema(?string $modelClass = null): array
    {
        return [
            WorkflowValueInput::make('items')->label('Что подать в цикл')->required()
                ->default('{{ $json }}')->placeholder('{{ $("Получить заказы").result }}')
                ->helperText('Выберите список из предыдущей ноды или укажите JSON-массив. Внутри ветки текущий элемент доступен через $("Цикл").'),
            Select::make('mode')->label('Сколько элементов обработать')->required()->default('all')
                ->options(['all' => 'Все элементы', 'once' => 'Один раз: только первый элемент'])
                ->helperText('«Каждый элемент» повторяет подключённую ветку. «После цикла» выполняется один раз после её завершения. Обратная связь не нужна.'),
        ];
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
            $mode = $config['mode'] ?? 'all';
            if (!in_array($mode, ['all', 'once'], true)) throw new \InvalidArgumentException('Выберите все элементы или выполнение один раз.');
            $items = self::items($config['items'] ?? null);
            return ['success' => true, 'output' => ['_workflow_loop' => true,
                'items' => $mode === 'once' ? array_slice($items, 0, 1) : $items, 'input_count' => count($items)]];
        } catch (\Throwable $error) {
            return ['success' => false, 'error' => $error->getMessage()];
        }
    }
}
