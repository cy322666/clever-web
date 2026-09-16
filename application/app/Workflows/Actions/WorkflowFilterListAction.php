<?php

namespace App\Workflows\Actions;

use App\Forms\Components\WorkflowValueInput;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Illuminate\Support\Arr;
use Leek\FilamentWorkflows\Concerns\WorkflowAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;

class WorkflowFilterListAction
{
    use WorkflowAction;

    public static function workflowType(): string { return 'workflow_filter_list'; }
    public static function workflowName(): string { return 'Фильтр списка'; }
    public static function workflowDescription(): string { return 'Проверить каждый элемент и оставить подходящие'; }
    public static function workflowIcon(): string { return 'heroicon-o-funnel'; }
    public static function workflowCategory(): string { return 'Управление потоком'; }
    public static function workflowDefaultConfig(): array { return ['items'=>'{{ $json.items }}', 'match'=>'all', 'rules'=>[]]; }
    public static function workflowConfigSchema(?string $modelClass = null): array
    {
        return [
            WorkflowValueInput::make('items')->label('Список')->default('{{ $json.items }}')->required(),
            Select::make('match')->label('Совпадение')->options(['all'=>'Все условия · И','any'=>'Любое условие · ИЛИ'])->default('all')->required(),
            Repeater::make('rules')->label('Условия для каждого элемента')->addActionLabel('Добавить условие')->defaultItems(0)->schema([
                WorkflowValueInput::make('field')->label('Поле элемента')->placeholder('pipeline_id, status_id, id…')->required(),
                Select::make('operator')->label('Сравнение')->options(['eq'=>'Равно','neq'=>'Не равно','gt'=>'Больше','gte'=>'Не меньше','lt'=>'Меньше','lte'=>'Не больше','contains'=>'Содержит','empty'=>'Пусто','not_empty'=>'Не пусто'])->default('eq')->required(),
                WorkflowValueInput::make('value')->label('Значение'),
            ]),
        ];
    }

    public function handle(array $config, ?WorkflowContext $context = null): array
    {
        try {
            $config = $context ? $context->resolve($config) : $config;
            $items = $config['items'] ?? null;
            if (is_string($items)) $items = json_decode($items, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($items) || !array_is_list($items)) throw new \RuntimeException('Выберите массив, например {{ $json.items }}.');
            if (count($items) > 10000) throw new \RuntimeException('Список превышает 10 000 элементов. Обработайте его частями.');
            $mode = $config['match'] ?? 'all';
            if (!in_array($mode, ['all','any'], true)) throw new \RuntimeException('Выберите И или ИЛИ.');
            $rules = $config['rules'] ?? [];
            if (!is_array($rules) || count($rules) > 50) throw new \RuntimeException('Допустимо до 50 условий.');
            foreach ($rules as $rule) {
                if (!is_array($rule) || !preg_match('/^[\w.-]+$/D', $rule['field'] ?? '') || !in_array($rule['operator'] ?? '', ['eq','neq','gt','gte','lt','lte','contains','empty','not_empty'], true)) throw new \RuntimeException('Заполните поле и сравнение каждого условия.');
            }
            $matched = array_values(array_filter($items, function ($item) use ($rules, $mode): bool {
                if ($rules === []) return true;
                $results = array_map(function ($rule) use ($item): bool {
                    $actual = is_array($item) ? Arr::get($item, $rule['field']) : null;
                    $value = $rule['value'] ?? null;
                    $equal = is_numeric($actual) && is_numeric($value) ? (float)$actual === (float)$value : $actual === $value;
                    $numeric = is_numeric($actual) && is_numeric($value);
                    return match ($rule['operator']) {
                        'eq' => $equal, 'neq' => $actual !== null && !$equal,
                        'gt' => $numeric && $actual > $value, 'gte' => $numeric && $actual >= $value,
                        'lt' => $numeric && $actual < $value, 'lte' => $numeric && $actual <= $value,
                        'contains' => is_scalar($actual) && is_scalar($value) && str_contains((string)$actual, (string)$value),
                        'empty' => $actual === null || $actual === '' || $actual === [],
                        'not_empty' => $actual !== null && $actual !== '' && $actual !== [],
                    };
                }, $rules);
                return $mode === 'all' ? !in_array(false, $results, true) : in_array(true, $results, true);
            }));
            return ['success'=>true, 'output'=>['items'=>$matched,'count'=>count($matched),'has_matches'=>$matched !== [],'input_count'=>count($items)]];
        } catch (\Throwable $e) { return ['success'=>false, 'error'=>$e->getMessage()]; }
    }
}
