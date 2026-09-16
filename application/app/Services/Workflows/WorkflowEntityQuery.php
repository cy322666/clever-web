<?php

namespace App\Services\Workflows;

use App\Models\amoCRM\Field;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** Documented entity filters; shared metadata keeps the form and executor aligned. */
final class WorkflowEntityQuery
{
    public static function supports(?string $operation): bool
    {
        return in_array($operation, ['tasks.list', 'leads.list', 'contacts.list', 'companies.list', 'customers.list'], true);
    }

    public static function fields(string $operation, ?int $userId = null): array
    {
        if (!self::supports($operation)) return [];
        $field = fn($label, $type = 'id', $multiple = true, $options = []) => compact('label', 'type', 'multiple', 'options');
        if ($operation === 'tasks.list') {
            return [
                'is_completed' => $field('Статус задачи', 'boolean', false, [0 => 'Открыта', 1 => 'Завершена']),
                'entity_type' => $field('Тип связанной сущности', 'choice', false, ['leads' => 'Сделка', 'contacts' => 'Контакт', 'companies' => 'Компания', 'customers' => 'Покупатель']),
                'entity_id' => $field('ID связанной сущности'),
                'responsible_user_id' => $field('Ответственный'),
                'task_type' => $field('Тип задачи'),
                'id' => $field('ID задачи'),
                'updated_at' => $field('Дата изменения', 'date', false),
            ];
        }
        $fields = [
            'id' => $field('ID'), 'name' => $field('Название', 'text'),
            'responsible_user_id' => $field('Ответственный'),
            'created_by' => $field('Кем создано'), 'updated_by' => $field('Кем изменено'),
            'created_at' => $field('Дата создания', 'date', false),
            'updated_at' => $field('Дата изменения', 'date', false),
            'closest_task_at' => $field('Дата ближайшей задачи', 'date', false),
        ];
        if ($operation === 'leads.list') $fields = [
            'pipeline_id' => $field('Воронка'), 'statuses' => $field('Этап сделки', 'status'),
            'price' => $field('Бюджет', 'number', false),
            'closed_at' => $field('Дата закрытия', 'date', false),
        ] + $fields;
        if ($operation === 'customers.list') $fields += [
            'status_id' => $field('ID статуса покупателя'),
            'next_price' => $field('Ожидаемая сумма покупки', 'number', false),
            'next_date' => $field('Дата следующей покупки', 'date', false),
        ];
        if (!$userId) return $fields;
        $entity = explode('.', $operation)[0];
        foreach (Field::query()->where('user_id', $userId)->where('entity_type', $entity)->where('active', true)->orderBy('sort')->get() as $custom) {
            $type = match ($custom->type) {
                'numeric' => 'number', 'date', 'date_time' => 'date',
                'select', 'multiselect', 'radiobutton' => 'enum', 'checkbox' => 'boolean',
                'text', 'url', 'textarea', 'streetaddress' => 'text', default => null,
            };
            if (!$type || !(int)$custom->field_id) continue;
            $options = $type === 'boolean' ? [0 => 'Нет', 1 => 'Да'] : [];
            foreach ($type === 'enum' ? ((is_array($custom->enums) ? $custom->enums : json_decode($custom->enums ?? '[]', true)) ?: []) : [] as $enum) {
                if (is_array($enum) && isset($enum['id'], $enum['value'])) $options[$enum['id']] = $enum['value'];
            }
            $fields['custom:'.$custom->field_id] = $field($custom->name.' · допполе', $type, !in_array($type, ['date', 'boolean']), $options);
        }
        return $fields;
    }

    public static function operators(array $field): array
    {
        return ['eq' => 'Равно'] + (in_array($field['type'] ?? '', ['number', 'date'], true) ? ['from' => 'Не меньше / от', 'to' => 'Не больше / до'] : []);
    }

    public static function sorts(string $operation): array
    {
        return ['id' => 'ID', 'created_at' => 'Дата создания'] + ($operation === 'tasks.list'
            ? ['complete_till' => 'Срок выполнения'] : ['updated_at' => 'Дата изменения']);
    }

    public static function build(array $config, ?int $userId = null): array
    {
        $operation = (string)($config['operation'] ?? '');
        if (!self::supports($operation)) throw new InvalidArgumentException('Для этого запроса используйте параметры или JSON.');
        $query = ['limit' => self::integer($config['limit'] ?? 50, 'Лимит', 250), 'page' => self::integer($config['page'] ?? 1, 'Страница')];
        if ($operation !== 'tasks.list' && filled($config['query'] ?? null)) {
            if (!is_scalar($config['query'])) throw new InvalidArgumentException('Поиск должен быть строкой.');
            $query['query'] = (string)$config['query'];
        }
        $fields = self::fields($operation, $userId);
        $rows = $config['filters'] ?? [];
        if (!is_array($rows) || count($rows) > 50) throw new InvalidArgumentException('Не более 50 фильтров в запросе.');
        $filter = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['field'] ?? null) || !is_string($row['operator'] ?? 'eq')) throw new InvalidArgumentException('Проверьте поле и условие фильтра.');
            $key = $row['field'] ?? '';
            $field = $fields[$key] ?? throw new InvalidArgumentException('Неизвестное или недоступное поле фильтра: '.$key);
            $operator = $row['operator'] ?? 'eq';
            if (!isset(self::operators($field)[$operator])) throw new InvalidArgumentException('Недопустимое сравнение для поля «'.$field['label'].'».');
            $value = $row['value'] ?? null;
            $values = is_array($value) && array_is_list($value) ? $value : [$value];
            if (!$values || (!$field['multiple'] && count($values) > 1) || ($operator !== 'eq' && count($values) > 1)) throw new InvalidArgumentException('Проверьте количество значений фильтра «'.$field['label'].'».');
            $custom = str_starts_with($key, 'custom:');
            if ($custom) $target = &$filter['custom_fields_values'][(int)substr($key, 7)];
            else $target = &$filter[$key];
            foreach ($values as $value) {
                $value = self::value($value, $field);
                if ($field['type'] === 'status') {
                    $target[] = ['pipeline_id' => self::integer($row['pipeline_id'] ?? null, 'Воронка для этапа'), 'status_id' => $value];
                } elseif (in_array($field['type'], ['date', 'number'], true) && (!$field['multiple'] || $operator !== 'eq')) {
                    if ($target !== null && array_is_list($target)) throw new InvalidArgumentException('Не смешивайте список значений и диапазон одного поля.');
                    foreach ($operator === 'eq' ? ['from', 'to'] : [$operator] as $bound) {
                        $target[$bound] = isset($target[$bound]) ? ($bound === 'from' ? max($target[$bound], $value) : min($target[$bound], $value)) : $value;
                    }
                    if (isset($target['from'], $target['to']) && $target['from'] > $target['to']) throw new InvalidArgumentException('В фильтре «От» больше «До».');
                } elseif ($field['multiple']) {
                    if ($target !== null && !array_is_list($target)) throw new InvalidArgumentException('Не смешивайте список значений и диапазон одного поля.');
                    $target[] = $value;
                } else {
                    if ($target !== null && $target !== $value) throw new InvalidArgumentException('Противоречивые значения поля «'.$field['label'].'».');
                    $target = $value;
                }
            }
            unset($target);
        }
        if (isset($filter['entity_id']) && !isset($filter['entity_type'])) throw new InvalidArgumentException('Для ID связанной сущности добавьте фильтр «Тип связанной сущности».');
        if ($filter) $query['filter'] = $filter;
        if (filled($config['sort'] ?? null)) {
            $sort = $config['sort']; $direction = $config['direction'] ?? 'asc';
            if (!isset(self::sorts($operation)[$sort]) || !in_array($direction, ['asc', 'desc'], true)) throw new InvalidArgumentException('Недопустимая сортировка запроса.');
            $query['order'] = [$sort => $direction];
        }
        return $query;
    }

    private static function value(mixed $value, array $field): mixed
    {
        if (!is_scalar($value) || $value === '') throw new InvalidArgumentException('Заполните значение поля «'.$field['label'].'».');
        return match ($field['type']) {
            'id', 'status', 'enum' => self::integer($value, $field['label']),
            'boolean' => in_array($value, [0, 1, '0', '1', false, true], true) ? (int)$value : throw new InvalidArgumentException('Выберите Да/Нет или статус задачи.'),
            'choice' => array_key_exists((string)$value, $field['options']) ? (string)$value : throw new InvalidArgumentException('Неизвестный тип сущности.'),
            'number' => is_numeric($value) && is_finite((float)$value) ? (float)$value : throw new InvalidArgumentException('Укажите число для «'.$field['label'].'».'),
            'date' => self::date($value),
            default => (string)$value,
        };
    }

    private static function date(mixed $value): int
    {
        if (is_numeric($value)) return self::integer($value, 'Unix-время');
        try { return Carbon::parse((string)$value, config('app.timezone'))->timestamp; }
        catch (\Throwable) { throw new InvalidArgumentException('Укажите дату YYYY-MM-DD или Unix-время.'); }
    }

    private static function integer(mixed $value, string $label, int $max = PHP_INT_MAX): int
    {
        if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 1 || (int)$value > $max) throw new InvalidArgumentException($label.': укажите целое число от 1 до '.$max.'.');
        return (int)$value;
    }
}
