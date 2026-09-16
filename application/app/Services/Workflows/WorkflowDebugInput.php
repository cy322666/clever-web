<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;
use App\Services\amoCRM\Client;
use App\Workflows\Triggers\AmoCrmWebhookTriggerCatalog;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class WorkflowDebugInput
{
    public static function entities(): array
    {
        return ['lead' => 'Сделка', 'contact' => 'Контакт', 'company' => 'Компания', 'customer' => 'Покупатель', 'task' => 'Задача', 'talk' => 'Беседа', 'message'=>'Входящее сообщение', 'outgoing_message'=>'Исходящее сообщение', 'unsorted'=>'Неразобранное', 'chat_template_review' => 'Шаблон сообщения', 'payload' => 'Произвольные данные / вебхук'];
    }

    public static function events(string $entity): array
    {
        if ($entity === 'payload') return ['webhook' => 'Входящий вебхук', 'manual' => 'Ручной запуск'];
        $events = ['manual' => 'Ручной запуск / кнопка'];
        foreach (AmoCrmWebhookTriggerCatalog::classes() as $class) {
            $config = $class::defaultConfig();
            if ($config['entity'] === $entity) $events[$config['event']] = $class::name();
        }
        return $events;
    }

    public static function fields(string $entity): array
    {
        $fields = ['id' => 'ID', 'name' => 'Название', 'responsible_user_id' => 'ID ответственного'];
        if (in_array($entity, ['message', 'outgoing_message'], true)) $fields = ['id'=>'ID сообщения', 'text'=>'Текст сообщения', 'chat_id'=>'ID чата', 'talk_id'=>'ID беседы', 'contact_id'=>'ID контакта', 'origin'=>'Источник', 'message_type'=>'Тип сообщения', 'author.type'=>'Тип автора', 'author.name'=>'Имя автора', 'element_id'=>'ID связанной сущности', 'element_type'=>'Тип связанной сущности'];
        if ($entity === 'unsorted') $fields = ['uid'=>'UID заявки', 'category'=>'Категория', 'source'=>'Источник', 'pipeline_id'=>'ID воронки', 'action'=>'Результат: accept / decline'];
        if ($entity === 'lead') $fields += ['pipeline_id' => 'ID воронки', 'status_id' => 'ID статуса', 'price' => 'Бюджет', 'old_status_id' => 'Предыдущий статус', 'old_pipeline_id' => 'Предыдущая воронка'];
        if ($entity === 'contact') $fields['first_name'] = 'Имя';
        if ($entity === 'customer') $fields += ['status_id' => 'ID статуса', 'next_price' => 'Сумма следующей покупки'];
        if ($entity === 'task') $fields += ['text' => 'Текст задачи', 'entity_id' => 'ID связанной сущности', 'entity_type' => 'Тип связанной сущности', 'task_type_id' => 'ID типа задачи', 'is_completed' => 'Завершена', 'complete_till' => 'Срок (Unix-время)'];
        return $fields + ['created_at' => 'Создано (Unix-время)', 'updated_at' => 'Изменено (Unix-время)', '__custom' => 'Другое поле…'];
    }

    public static function defaults(string $entity, array $item = []): array
    {
        $keys = match ($entity) {
            'payload' => ['id'], 'lead' => ['id', 'name', 'pipeline_id', 'status_id'],
            'message', 'outgoing_message' => ['id', 'text', 'chat_id', 'contact_id'],
            'unsorted' => ['uid', 'source', 'pipeline_id'],
            'task' => ['id', 'text', 'entity_id', 'entity_type'], default => ['id', 'name'],
        };
        return array_map(function ($key) use ($item, $entity): array {
            $type = in_array($key, ['id', 'pipeline_id', 'status_id', 'entity_id', 'contact_id'], true) ? 'number' : 'text';
            if ($key === 'id' && in_array($entity, ['message', 'outgoing_message'], true)) $type = 'text';
            return ['key' => $key, 'path' => '', 'type' => $type, 'value' => isset($item[$key]) && is_scalar($item[$key]) ? (string) $item[$key] : ''];
        }, $keys);
    }

    /** The event envelope matches WorkflowAmoCrmWebhookService; custom fields only modify the item. */
    public static function build(string $entity, string $event, array $rows, array $base = [], array $account = []): array
    {
        if (!isset(self::entities()[$entity]) || !isset(self::events($entity)[$event])) self::fail('Выберите совместимые сущность и событие.');
        if (count($rows) > 50) self::fail('Не более 50 полей в конструкторе.');
        $item = $base;
        $paths = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) self::fail('Проверьте поле '.($index + 1).'.');
            $path = trim((string) (($row['key'] ?? '') === '__custom' ? ($row['path'] ?? '') : ($row['key'] ?? '')));
            $type = $row['type'] ?? 'text';
            $value = $row['value'] ?? '';
            if (!is_scalar($value) && $value !== null) self::fail('Значение поля должно быть текстом, числом или логическим значением.');
            if ($path === '' && ($value === '' || $value === null)) continue;
            if (!preg_match('/^[a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+){0,9}$/D', $path) || preg_match('/(?:^|\.)(?:__proto__|prototype|constructor)(?:\.|$)/', $path)) self::fail('Укажите путь поля буквами, цифрами и точками.');
            foreach ($paths as $used) if ($used === $path || str_starts_with($used, $path.'.') || str_starts_with($path, $used.'.')) self::fail('Пути полей не должны повторяться или перекрывать друг друга.');
            $paths[] = $path;
            if (strlen((string) $value) > 20000) self::fail('Значение поля слишком длинное.');
            if ($value === '' && $type === 'number') { Arr::forget($item, $path); continue; }
            $value = match ($type) {
                'text' => (string) $value,
                'number' => self::number($value),
                'boolean' => match ((string) $value) { 'true', '1' => true, 'false', '0' => false, default => self::fail('Выберите «Да» или «Нет».') },
                'null' => null,
                default => self::fail('Неизвестный тип значения.'),
            };
            if (!in_array($entity, ['payload', 'message', 'outgoing_message'], true) && $path === 'id' && $value !== null && $value !== '' && (!is_numeric($value) || (float) $value <= 0 || (float) $value !== (float) (int) $value)) self::fail('ID должен быть положительным целым числом.');
            Arr::set($item, $path, $value);
        }
        if ($entity === 'payload') return ['source' => $event === 'webhook' ? 'generic-webhook' : 'manual', 'body' => $item, 'payload' => $item, 'query' => [], 'headers' => [], 'method' => 'POST'];
        $action = $event === 'manual' ? 'manual' : substr($event, 0, -(strlen($entity) + 1));
        $plural = ['lead' => 'leads', 'contact' => 'contacts', 'company' => 'contacts', 'customer' => 'customers', 'task' => 'tasks', 'talk' => 'talks', 'message'=>'message', 'outgoing_message'=>'outgoing_message', 'unsorted'=>'unsorted', 'chat_template_review' => 'chat_template_reviews'][$entity];
        if (in_array($entity, ['contact', 'company'], true)) $item['type'] = $entity;
        $data = ['source' => $event === 'manual' ? 'manual' : 'amocrm', 'event' => $event, 'entity' => $entity, 'action' => $action,
            'item' => $item, $entity => $item, $action => $item,
            'payload' => [$plural => [$action => [$item]]], 'received_at' => now()->toIso8601String()];
        if ($account !== []) $data['account'] = Arr::only($account, ['id', 'user_id', 'subdomain']);
        return $data;
    }

    /** Load only a record in the signed-in user's CRM; no arbitrary URLs or writes. */
    public function load(Account $account, int $userId, string $entity, string $id): array
    {
        if ((int) $account->user_id !== $userId || !$account->active || !filled($account->refresh_token)) self::fail('Нет доступного подключения amoCRM.');
        $plural = ['lead' => 'leads', 'contact' => 'contacts', 'company' => 'companies', 'customer' => 'customers', 'task' => 'tasks'][$entity] ?? null;
        if (!$plural) self::fail('Для этой сущности задайте пример полями или возьмите прошлый запуск.');
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $id)) self::fail('Введите положительный ID сущности.');
        $item = $this->client($account)->requestV4('GET', '/api/v4/'.$plural.'/'.$id);
        if ((string) ($item['id'] ?? '') !== (string) $id) self::fail('Сущность не найдена или недоступна.');
        return $item;
    }

    protected function client(Account $account): Client { return new Client($account); }

    private static function number(mixed $value): int|float
    {
        if (!is_numeric($value) || !is_finite((float) $value)) self::fail('Введите корректное число.');
        if (preg_match('/^-?[0-9]+$/D', (string) $value) && filter_var($value, FILTER_VALIDATE_INT) !== false) return (int) $value;
        return (float) $value;
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['debugInputBuilder' => $message]);
    }
}
