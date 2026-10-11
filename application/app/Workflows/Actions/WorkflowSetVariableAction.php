<?php

namespace App\Workflows\Actions;

use App\Forms\Components\WorkflowValueInput;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Leek\FilamentWorkflows\Concerns\WorkflowAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;

class WorkflowSetVariableAction
{
    use WorkflowAction;

    public static function workflowType(): string { return 'workflow_set_variable'; }
    public static function workflowName(): string { return 'Присвоить'; }
    public static function workflowDescription(): string { return 'Переменные для текущего прохода или итерации'; }
    public static function workflowIcon(): string { return 'heroicon-o-variable'; }
    public static function workflowCategory(): string { return 'Управление потоком'; }
    public static function workflowDefaultConfig(): array { return ['assignments' => [['name' => 'contact_id', 'value' => '']]]; }

    public static function workflowConfigSchema(?string $modelClass = null): array
    {
        return [Repeater::make('assignments')->label('Переменные')->addActionLabel('Добавить переменную')
            ->minItems(1)->maxItems(50)->defaultItems(1)->schema([
                TextInput::make('name')->label('Имя')->placeholder('contact_id')->required()->maxLength(64)
                    ->regex('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D'),
                WorkflowValueInput::make('value')->label('Значение')->placeholder('{{ $("Найти контакт").first().id }}'),
            ])->helperText('В каждой ветке задайте одно имя, например contact_id. Далее используйте {{ $vars.contact_id }}. Изменения внутри цикла не переходят в следующую итерацию или в другой запуск.')];
    }

    public static function validateAssignments(mixed $rows): array
    {
        if (!is_array($rows) || $rows === [] || count($rows) > 50) throw new \InvalidArgumentException('Добавьте от 1 до 50 переменных.');
        $values = [];
        foreach ($rows as $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $name)) throw new \InvalidArgumentException('Имя переменной: латинская буква, затем буквы, цифры или подчёркивание, до 64 символов.');
            if (array_key_exists($name, $values)) throw new \InvalidArgumentException('Имя переменной «'.$name.'» повторяется.');
            if (!array_key_exists('value', $row)) throw new \InvalidArgumentException('Укажите значение переменной «'.$name.'».');
            $values[$name] = $row['value'];
        }
        return $values;
    }

    /** Used for both a fresh execution and replay of its saved result. */
    public static function applyOutput(array $values, WorkflowContext $context): void
    {
        $context->setVariable('_workflow_values', array_replace((array) $context->getVariable('_workflow_values', []), $values));
    }

    public function handle(array $config, ?WorkflowContext $context = null): array
    {
        try {
            // Engines resolve expressions before invoking the action, exactly once.
            $values = self::validateAssignments($config['assignments'] ?? null);
            if ($context === null) throw new \InvalidArgumentException('Для присваивания нужен контекст выполнения.');
            self::applyOutput($values, $context);
            return ['success' => true, 'output' => $values];
        } catch (\InvalidArgumentException $error) {
            return ['success' => false, 'error' => $error->getMessage()];
        }
    }
}
