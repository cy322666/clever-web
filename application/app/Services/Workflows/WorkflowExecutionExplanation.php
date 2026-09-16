<?php

namespace App\Services\Workflows;

/** Explain recorded facts only; never evaluate an old run against today's configuration. */
final class WorkflowExecutionExplanation
{
    public static function forResult(array $result): array
    {
        $status = $result['status'] ?? '';
        $output = is_array($result['output'] ?? null) ? $result['output'] : [];
        $summary = ['title' => 'Шаг выполнен', 'tone' => 'success', 'details' => [], 'note' => ''];
        if (in_array($status, ['error', 'failed', 'validation_error'], true)) {
            return array_replace($summary, ['title' => 'Шаг завершился с ошибкой', 'tone' => 'danger', 'note' => self::value($result['error'] ?? 'Причина не записана.')]);
        }
        if ($status === 'skipped') return array_replace($summary, ['title' => 'Шаг пропущен', 'tone' => 'gray']);
        if ($status === 'simulated') return array_replace($summary, ['title' => 'Проверка завершена без реальных изменений', 'tone' => 'info']);
        if (!in_array($status, ['completed', 'success'], true)) return array_replace($summary, ['title' => 'Шаг ещё не завершён', 'tone' => 'gray']);
        if (!in_array($result['type'] ?? '', ['condition', 'control-condition'], true)) {
            if (is_array($output['items'] ?? null)) $summary['note'] = 'Получено записей: '.count($output['items']).'.';
            return $summary;
        }
        if (!array_key_exists('passed', $output)) return array_replace($summary, ['title' => 'Проверка выполнена', 'note' => 'Результат сравнения не записан в этом запуске.']);
        $passed = filter_var($output['passed'], FILTER_VALIDATE_BOOLEAN);
        $summary['title'] = $passed ? 'Условие выполнено → ветка «Да»' : 'Условие не выполнено → ветка «Нет»';
        $summary['tone'] = $passed ? 'success' : 'info';
        $summary['note'] = $passed ? '' : 'Это результат проверки, а не ошибка выполнения.';
        $conditions = is_array($result['input']['conditions'] ?? null) ? $result['input']['conditions'] : [];
        $conditionResults = is_array($output['condition_results'] ?? null) ? $output['condition_results'] : [];
        foreach (array_values($conditionResults) as $index => $row) {
            if (!is_array($row)) continue;
            $operator = is_string($row['operator'] ?? null) ? $row['operator'] : 'equals';
            $summary['details'][] = [
                'label' => self::field($conditions[$index]['left'] ?? $row['left'] ?? 'Значение'),
                'actual' => array_key_exists('left_value', $row) ? self::value($row['left_value']) : 'не записано',
                'expected' => array_key_exists('right_value', $row) ? self::value($row['right_value']) : 'не записано',
                'operator' => self::operator($operator),
                'unary' => in_array($operator, ['is_empty', 'is_not_empty', 'is_null', 'is_not_null', 'is_true', 'is_false'], true),
                'passed' => filter_var($row['passed'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }
        return $summary;
    }

    public static function entity(array $context): string
    {
        $data = is_array($context['trigger_data'] ?? null) ? $context['trigger_data'] : [];
        $types = ['lead' => 'Сделка', 'contact' => 'Контакт', 'company' => 'Компания', 'customer' => 'Покупатель', 'task' => 'Задача', 'talk' => 'Беседа'];
        $type = is_string($data['entity'] ?? null) ? $data['entity'] : null;
        if (!isset($types[$type ?? ''])) {
            $type = null;
            foreach ($types as $key => $_) if (is_array($data[$key] ?? null) && !empty($data[$key]['id'])) { $type = $key; break; }
        }
        if (!$type) return '';
        $item = $data[$type] ?? $data['item'] ?? [];
        if (!is_array($item)) return '';
        return $types[$type].(isset($item['id']) ? ' #'.self::value($item['id']) : '').(!empty($item['name']) ? ' · '.self::value($item['name']) : '');
    }

    private static function field(mixed $expression): string
    {
        $key = is_scalar($expression) ? trim((string) $expression, "{} \t\n\r") : '';
        return ['lead.id' => 'ID сделки', 'lead.name' => 'Название сделки', 'lead.price' => 'Бюджет сделки', 'lead.pipeline_id' => 'Воронка сделки', 'lead.status_id' => 'Статус сделки', 'status.status_id' => 'Статус сделки', 'status.pipeline_id' => 'Воронка сделки', 'lead.responsible_user_id' => 'Ответственный за сделку', 'contact.id' => 'ID контакта', 'company.id' => 'ID компании', 'task.id' => 'ID задачи'][$key] ?? self::value($expression);
    }

    private static function operator(string $operator): string
    {
        return ['equals' => 'равно', 'strict_equals' => 'строго равно', 'not_equals' => 'не равно', 'gt' => 'больше', 'gte' => 'не меньше', 'lt' => 'меньше', 'lte' => 'не больше', 'contains' => 'содержит', 'not_contains' => 'не содержит', 'starts_with' => 'начинается с', 'ends_with' => 'заканчивается на', 'in' => 'входит в список', 'not_in' => 'не входит в список', 'is_empty' => 'пустое', 'is_not_empty' => 'не пустое', 'is_null' => 'не задано', 'is_not_null' => 'задано', 'is_true' => 'истина', 'is_false' => 'ложь', 'matches' => 'соответствует шаблону'][$operator] ?? $operator;
    }

    private static function value(mixed $value): string
    {
        if ($value === null) return 'не задано';
        if ($value === '') return 'пустая строка';
        if (is_bool($value)) return $value ? 'да' : 'нет';
        return mb_strimwidth(is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_UNICODE) ?: 'не записано'), 0, 500, '…');
    }
}
