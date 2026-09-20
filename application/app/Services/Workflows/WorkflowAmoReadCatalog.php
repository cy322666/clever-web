<?php

namespace App\Services\Workflows;

use InvalidArgumentException;

/** Public amoCRM v4 read endpoints; credentials always come from the workflow account. */
final class WorkflowAmoReadCatalog
{
    public static function operations(): array
    {
        $items = [];
        $add = function ($key, $group, $name, $path) use (&$items) {
            $items[$key] = compact('group', 'name', 'path');
        };
        foreach (['leads' => 'Сделки', 'contacts' => 'Контакты', 'companies' => 'Компании', 'customers' => 'Покупатели', 'tasks' => 'Задачи'] as $entity => $group) {
            $add($entity.'.list', $group, 'Получить список', '/api/v4/'.$entity);
            $add($entity.'.one', $group, 'Получить по ID', '/api/v4/'.$entity.'/{id}');
            if ($entity === 'tasks') continue;
            foreach (['links' => 'Связи сущности', 'notes' => 'Примечания сущности'] as $suffix => $name) $add($entity.'.'.$suffix, $group, $name, '/api/v4/'.$entity.'/{entity_id}/'.$suffix);
            $add($entity.'.notes.all', $group, 'Все примечания', '/api/v4/'.$entity.'/notes');
            $add($entity.'.notes.one', $group, 'Примечание по ID', '/api/v4/'.$entity.'/notes/{id}');
            $add($entity.'.notes.entity.one', $group, 'Примечание сущности по ID', '/api/v4/'.$entity.'/{entity_id}/notes/{id}');
            $add($entity.'.tags', $group, 'Теги', '/api/v4/'.$entity.'/tags');
            if (in_array($entity, ['leads', 'customers'], true)) $add($entity.'.subscriptions', $group, 'Подписчики', '/api/v4/'.$entity.'/{id}/subscriptions');
            foreach (['custom_fields' => 'Поля', 'custom_fields/groups' => 'Группы полей'] as $suffix => $name) {
                $key = str_replace('/', '.', $suffix);
                $add($entity.'.'.$key, $group, $name, '/api/v4/'.$entity.'/'.$suffix);
                $add($entity.'.'.$key.'.one', $group, $name.' · по ID', '/api/v4/'.$entity.'/'.$suffix.'/{id}');
            }
        }
        foreach ([
            ['unsorted', 'Неразобранное', 'Неразобранное', 'leads/unsorted'],
            ['pipelines', 'Воронки', 'Воронки', 'leads/pipelines'],
            ['statuses', 'Воронки', 'Этапы', 'leads/pipelines/{pipeline_id}/statuses'],
            ['catalogs', 'Списки и товары', 'Списки', 'catalogs'],
            ['elements', 'Списки и товары', 'Элементы списка / товары', 'catalogs/{catalog_id}/elements'],
            ['catalog_fields', 'Списки и товары', 'Поля списка', 'catalogs/{catalog_id}/custom_fields'],
            ['transactions', 'Покупатели', 'Транзакции', 'customers/transactions'],
            ['customer_transactions', 'Покупатели', 'Транзакции покупателя', 'customers/{entity_id}/transactions'],
            ['customer_statuses', 'Покупатели', 'Статусы', 'customers/statuses'],
            ['segments', 'Покупатели', 'Сегменты', 'customers/segments'],
            ['segment_fields', 'Покупатели', 'Поля сегмента', 'customers/segments/custom_fields'],
            ['events', 'События', 'События', 'events'],
            ['users', 'Аккаунт', 'Сотрудники', 'users'],
            ['roles', 'Аккаунт', 'Роли', 'roles'],
            ['bots', 'Аккаунт', 'Salesbot', 'bots'],
            ['widgets', 'Аккаунт', 'Виджеты', 'widgets'],
            ['sources', 'Источники', 'Источники', 'sources'],
            ['website_buttons', 'Источники', 'Кнопки сайта', 'website_buttons'],
            ['talks', 'Беседы', 'Беседы', 'talks'],
            ['templates', 'Беседы', 'Шаблоны чатов', 'chats/templates'],
        ] as [$key, $group, $name, $path]) {
            $add($key.'.list', $group, $name, '/api/v4/'.$path);
            $add($key.'.one', $group, $name.' · по ID', '/api/v4/'.$path.'/{id}');
        }
        $add('account', 'Аккаунт', 'Параметры аккаунта', '/api/v4/account');
        $add('webhooks', 'Аккаунт', 'Вебхуки', '/api/v4/webhooks');
        $add('event_types', 'События', 'Типы событий', '/api/v4/events/types');
        $add('unsorted_summary', 'Неразобранное', 'Сводка', '/api/v4/leads/unsorted/summary');
        $add('custom', 'Другое', 'Запрос amoCRM', '');
        return $items;
    }

    /** New-node catalog only. The full registry remains available to saved workflows. */
    public static function availableOperations(): array
    {
        $eligible = [];
        foreach (self::operations() as $key => $item) {
            if (in_array($item['group'], ['Неразобранное', 'Воронки', 'Аккаунт', 'Источники', 'Беседы'], true)
                || str_contains($item['path'], '/custom_fields/groups')) continue;

            $parts = explode('.', $key);
            if (in_array('notes', $parts, true) || in_array('tags', $parts, true)) {
                $item['entity_group'] = $item['group'];
                $item['group'] = in_array('notes', $parts, true) ? 'Примечания' : 'Теги';
            }
            $eligible[$key] = $item;
        }

        $items = [];
        foreach ($eligible as $key => $item) {
            $family = self::familyKey($key, $eligible);
            if (!isset($items[$family])) {
                $items[$family] = $eligible[$family] ?? $item;
                $items[$family]['variants'] = [];
            }
            $items[$family]['variants'][$key] = self::variantLabel($key, $item);
        }

        foreach ($items as $key => &$item) {
            if (count($item['variants']) < 2) continue;
            if (isset($item['variants'][$key.'.one'])) $item['variants'][$key] = 'Список';
            if (str_ends_with($key, '.notes.all')) {
                $item['name'] = 'Примечания';
                $entity = explode('.', $key)[0];
                $order = [$entity.'.notes.all', $entity.'.notes.one', $entity.'.notes', $entity.'.notes.entity.one'];
                $item['variants'] = self::orderVariants($item['variants'], $order);
            } elseif ($key === 'transactions.list') {
                $item['name'] = 'Транзакции';
                $item['variants'] = self::orderVariants($item['variants'], ['transactions.list', 'transactions.one', 'customer_transactions.list', 'customer_transactions.one']);
            } elseif (preg_match('/^(leads|contacts|companies|customers|tasks)\.list$/', $key)) {
                $item['name'] = 'Получить';
                $item['node_name'] = 'Получить '.mb_strtolower($item['group']);
            }
        }
        unset($item);

        return $items;
    }

    /** @return array<string, string> */
    public static function variantOptions(string $operation): array
    {
        foreach (self::availableOperations() as $item) {
            if (isset($item['variants'][$operation])) return $item['variants'];
        }

        $item = self::operations()[$operation] ?? null;
        return $item ? [$operation => $item['name']] : [];
    }

    public static function options(): array
    {
        $groups = [];
        foreach (self::availableOperations() as $key => $item) {
            $groups[$item['group']][$key] = isset($item['entity_group']) ? $item['entity_group'].' · '.$item['name'] : $item['name'];
        }
        $order = array_flip(['Сделки', 'Контакты', 'Компании', 'Покупатели', 'Задачи', 'Примечания', 'Теги', 'Списки и товары', 'События', 'Другое']);
        return array_replace(array_intersect_key($order, $groups), $groups);
    }

    private static function familyKey(string $key, array $items): string
    {
        if (preg_match('/^(leads|contacts|companies|customers)\.notes(?:\.|$)/', $key, $match)) {
            return $match[1].'.notes.all';
        }
        if (str_starts_with($key, 'transactions.') || str_starts_with($key, 'customer_transactions.')) {
            return 'transactions.list';
        }
        if (str_ends_with($key, '.one')) {
            $base = substr($key, 0, -4);
            if (isset($items[$base])) return $base;
            if (isset($items[$base.'.list'])) return $base.'.list';
        }
        return $key;
    }

    private static function variantLabel(string $key, array $item): string
    {
        if (str_ends_with($key, '.notes.all')) return 'Все примечания';
        if (str_ends_with($key, '.notes.entity.one')) return 'По сущности и ID примечания';
        if (str_ends_with($key, '.notes.one')) return 'По ID примечания';
        if (str_ends_with($key, '.notes')) return 'Примечания сущности';
        return match ($key) {
            'transactions.list' => 'Все транзакции',
            'transactions.one' => 'Транзакция по ID',
            'customer_transactions.list' => 'Транзакции покупателя',
            'customer_transactions.one' => 'Транзакция покупателя по ID',
            default => str_ends_with($key, '.one') ? 'По ID' : (str_ends_with($key, '.list') ? 'Список' : $item['name']),
        };
    }

    private static function orderVariants(array $variants, array $order): array
    {
        $ordered = [];
        foreach ($order as $key) if (isset($variants[$key])) $ordered[$key] = $variants[$key];
        return $ordered + $variants;
    }

    public static function editorConfig(array $config): array
    {
        if (($config['body_mode'] ?? '') !== 'json') return $config;
        // JSON was always encoded as GET query parameters, never sent as a body.
        // Flatten literal objects for editing without changing the outgoing query.
        try { $query = WorkflowJsonBody::parse($config['json_body'] ?? '{}'); }
        catch (InvalidArgumentException) { return $config; } // Keep dynamic/invalid legacy input editable.
        $parameters = [];
        $flatten = function(array $items, string $prefix = '') use (&$flatten, &$parameters): bool {
            foreach ($items as $key => $value) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/D', (string)$key)) return false;
                $name = $prefix === '' ? (string)$key : $prefix.'['.$key.']';
                if (is_array($value)) {
                    if (!$flatten($value, $name)) return false;
                } elseif ($value !== null) {
                    $parameters[] = ['name'=>$name, 'value'=>is_bool($value) ? (int)$value : $value];
                }
            }
            return true;
        };
        if (!$flatten($query)) return $config;
        $config['body_mode'] = 'fields';
        $config['parameters'] = $parameters;
        unset($config['json_body']);
        return $config;
    }

    public static function build(array $config, ?int $userId = null): array
    {
        $operation = self::operations()[$config['operation'] ?? ''] ?? throw new InvalidArgumentException('Выберите запрос amoCRM.');
        $path = $operation['path'] ?: (string)($config['request_path'] ?? '');
        $path = preg_replace_callback('/\{([a-z_]+)\}/', function ($match) use ($config) {
            $value = (string)($config[$match[1]] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $value)) throw new InvalidArgumentException('Заполните '.$match[1].' числом, идентификатором или выражением.');
            return $value;
        }, $path);
        // No host, credentials, encoded traversal, query string, redirects or mutating verbs.
        if (!preg_match('~^/api/v4/[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $path)) throw new InvalidArgumentException('Укажите путь /api/v4/… без домена и строки запроса.');
        if (($config['body_mode'] ?? '') === 'builder') {
            return ['path' => $path, 'query' => WorkflowEntityQuery::build($config, $userId)];
        }
        $query = ($config['body_mode'] ?? '') === 'json' ? WorkflowJsonBody::parse($config['json_body'] ?? '{}') : [];
        if (($config['body_mode'] ?? '') !== 'json') {
            foreach ($config['parameters'] ?? [] as $row) {
                $name = (string)($row['name'] ?? '');
                if (!preg_match('/^[a-zA-Z0-9_]+(?:\[[a-zA-Z0-9_]*\])*$/D', $name)) throw new InvalidArgumentException('Некорректное имя параметра запроса.');
                preg_match_all('/[a-zA-Z0-9_]+/', $name, $parts);
                $cursor = &$query;
                foreach ($parts[0] as $key) {
                    if ($cursor !== null && !is_array($cursor)) throw new InvalidArgumentException('Параметр одновременно задан как значение и объект.');
                    $cursor = &$cursor[$key];
                }
                $value = $row['value'] ?? '';
                if (str_ends_with($name, '[]')) {
                    $cursor ??= [];
                    if (!is_array($cursor)) throw new InvalidArgumentException('Параметр одновременно задан как значение и список.');
                    foreach (is_array($value) ? $value : [$value] as $item) $cursor[] = $item;
                } else $cursor = $value;
                unset($cursor);
            }
        }
        if (isset($query['limit']) && (!is_scalar($query['limit']) || !ctype_digit((string)$query['limit']) || (int)$query['limit'] < 1 || (int)$query['limit'] > 250)) throw new InvalidArgumentException('Лимит запроса: от 1 до 250.');
        return ['path' => $path, 'query' => $query];
    }
}
