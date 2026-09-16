<?php

namespace App\Services\Workflows;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** Builds the documented amoCRM GET /api/v4/leads query; never accepts arbitrary URLs. */
final class WorkflowLeadQuery
{
    public static function build(array $config): array
    {
        $query = [
            'limit' => self::positiveInteger($config['limit'] ?? 50, 'Лимит', 250),
            'page' => self::positiveInteger($config['page'] ?? 1, 'Страница'),
        ];
        if (filled($config['query'] ?? null)) {
            $query['query'] = (string) $config['query'];
        }
        $filter = [];
        if (filled($config['pipeline_id'] ?? null)) {
            $filter['pipeline_id'] = self::positiveInteger($config['pipeline_id'], 'Воронка');
        }
        if (filled($config['status_id'] ?? null)) {
            if (! isset($filter['pipeline_id'])) {
                throw new InvalidArgumentException('Для фильтра по этапу выберите воронку.');
            }
            $filter['statuses'] = [[
                'pipeline_id' => $filter['pipeline_id'],
                'status_id' => self::positiveInteger($config['status_id'], 'Этап'),
            ]];
        }
        if (filled($config['responsible_user_ids'] ?? null)) {
            $filter['responsible_user_id'] = array_map(
                fn ($id) => self::positiveInteger($id, 'Ответственный'),
                (array) $config['responsible_user_ids'],
            );
        }
        foreach ($config['filters'] ?? [] as $row) {
            $field = (string) ($row['field'] ?? '');
            $operator = (string) ($row['operator'] ?? 'eq');
            $value = $row['value'] ?? null;
            if (! in_array($operator, ['eq', 'from', 'to'], true) || ! is_scalar($value) || $value === '') {
                throw new InvalidArgumentException('Заполните поле, сравнение и значение фильтра.');
            }
            $custom = preg_match('/^custom:(\d+)$/', $field, $match) === 1;
            if (! $custom && ! in_array($field, ['id', 'name', 'price', 'created_at', 'updated_at', 'closed_at', 'closest_task_at'], true)) {
                throw new InvalidArgumentException('Неизвестное поле фильтра: '.$field);
            }
            $range = in_array($field, ['price', 'created_at', 'updated_at', 'closed_at', 'closest_task_at'], true);
            if (! $custom && ! $range && $operator !== 'eq') {
                throw new InvalidArgumentException('Для этого поля доступно только сравнение «Равно».');
            }
            if (str_ends_with($field, '_at') || ($custom && $operator !== 'eq' && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value))) {
                $value = is_numeric($value) ? (int) $value : Carbon::parse((string) $value, config('app.timezone'))->timestamp;
            }
            if ($field === 'price') {
                if (! is_numeric($value) || (float) $value < 0) {
                    throw new InvalidArgumentException('Бюджет должен быть неотрицательным числом.');
                }
                $value = (float) $value;
            }
            if ($field === 'id') {
                $value = self::positiveInteger($value, 'ID сделки');
            }
            if ($custom) {
                $key = self::positiveInteger($match[1], 'ID поля');
                $existing = $filter['custom_fields_values'][$key] ?? [];
                if ($existing !== [] && array_is_list($existing) !== ($operator === 'eq')) {
                    throw new InvalidArgumentException('Для одного поля используйте либо значения «Равно», либо диапазон «От/До».');
                }
                if ($operator === 'eq') {
                    $filter['custom_fields_values'][$key][] = $value;
                } else {
                    self::addBound($filter['custom_fields_values'][$key], $operator, $value);
                }
            } elseif ($range) {
                foreach ($operator === 'eq' ? ['from', 'to'] : [$operator] as $bound) {
                    self::addBound($filter[$field], $bound, $value);
                }
            } else {
                $filter[$field][] = $value;
            }
        }
        if ($filter !== []) {
            $query['filter'] = $filter;
        }
        $sort = (string) ($config['sort'] ?? 'created_at');
        $direction = (string) ($config['direction'] ?? 'desc');
        if (! in_array($sort, ['id', 'created_at', 'updated_at'], true) || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Недопустимая сортировка запроса.');
        }
        $query['order'] = [$sort => $direction];
        $query['with'] = 'contacts';

        return $query;
    }

    private static function addBound(?array &$range, string $bound, mixed $value): void
    {
        $range ??= [];
        $range[$bound] = isset($range[$bound])
            ? ($bound === 'from' ? max($range[$bound], $value) : min($range[$bound], $value))
            : $value;
        if (isset($range['from'], $range['to']) && $range['from'] > $range['to']) {
            throw new InvalidArgumentException('В фильтре значение «От» больше значения «До».');
        }
    }

    private static function positiveInteger(mixed $value, string $label, int $maximum = PHP_INT_MAX): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1 || (int) $value > $maximum) {
            throw new InvalidArgumentException($label.': укажите целое число от 1 до '.$maximum.'.');
        }

        return (int) $value;
    }
}
