<?php

namespace App\Services\Workflows;

use App\Workflows\Triggers\AmoCrmWebhookTriggerCatalog;

/** Business fields exposed by a start node, independent of recorded runs. */
final class WorkflowTriggerReferenceCatalog
{
    /** @return array<string, string> Field path => label. Optional webhook fields may be absent in an individual event. */
    public static function fields(string $type): array
    {
        if (in_array($type, ['amo-button', 'amo-bulk', 'digital-pipeline'], true)) {
            return ['id' => 'ID сущности', 'name' => 'Название сущности'];
        }
        if (!str_starts_with($type, 'amocrm-')) return [];

        $event = str_replace('-', '_', substr($type, 7));
        if (!in_array($event, AmoCrmWebhookTriggerCatalog::eventCodes(), true)) return [];
        [$action, $entity] = explode('_', $event, 2);

        if ($action === 'delete') {
            return $entity === 'unsorted' ? ['uid' => 'ID неразобранного'] : ['id' => 'ID сущности'];
        }
        if ($action === 'note') {
            return [
                'id' => 'ID примечания', 'element_id' => 'ID сущности',
                'note_type' => 'Тип примечания', 'text' => 'Текст', 'created_at' => 'Дата создания',
            ];
        }

        $fields = match ($entity) {
            'lead' => [
                'id' => 'ID сделки', 'name' => 'Название сделки', 'price' => 'Бюджет',
                'pipeline_id' => 'ID воронки', 'status_id' => 'ID этапа',
                'responsible_user_id' => 'ID ответственного',
            ],
            'contact' => ['id' => 'ID контакта', 'name' => 'Имя', 'responsible_user_id' => 'ID ответственного'],
            'company' => ['id' => 'ID компании', 'name' => 'Название', 'responsible_user_id' => 'ID ответственного'],
            'customer' => ['id' => 'ID покупателя', 'name' => 'Название', 'next_price' => 'Следующая покупка', 'responsible_user_id' => 'ID ответственного'],
            'task' => [
                'id' => 'ID задачи', 'text' => 'Текст', 'task_type' => 'Тип задачи',
                'element_id' => 'ID связанной сущности', 'element_type' => 'Тип связанной сущности',
                'responsible_user_id' => 'ID ответственного', 'status' => 'Статус задачи',
                'complete_till' => 'Срок выполнения',
            ],
            'talk' => [
                'id' => 'ID беседы', 'entity_id' => 'ID сущности', 'entity_type' => 'Тип сущности',
                'contact_id' => 'ID контакта', 'is_in_work' => 'В работе',
            ],
            'message' => [
                'id' => 'ID сообщения', 'chat_id' => 'ID чата', 'text' => 'Текст сообщения',
                'talk_id' => 'ID беседы', 'contact_id' => 'ID контакта',
                'element_id' => 'ID связанной сущности', 'element_type' => 'Тип связанной сущности',
                'entity_id' => 'ID сущности, если передан', 'entity_type' => 'Тип сущности, если передан',
                'created_at' => 'Дата сообщения', 'message_type' => 'Тип сообщения', 'origin' => 'Источник',
                'author.id' => 'ID автора', 'author.type' => 'Тип автора', 'author.name' => 'Имя автора',
                'attachment.type' => 'Тип вложения', 'attachment.link' => 'Ссылка на вложение',
                'attachment.file_name' => 'Имя файла',
            ],
            'outgoing_message' => [
                'id' => 'ID сообщения', 'chat_id' => 'ID чата', 'text' => 'Текст сообщения',
                'talk_id' => 'ID беседы', 'contact_id' => 'ID контакта',
                'created_at' => 'Дата сообщения', 'message_type' => 'Тип сообщения',
                'type' => 'Направление', 'origin' => 'Источник',
                'author.id' => 'ID автора', 'author.user_id' => 'ID пользователя amoCRM',
                'author.type' => 'Тип автора', 'author.name' => 'Имя автора',
                'recipient.id' => 'ID получателя', 'recipient.type' => 'Тип получателя',
                'recipient.name' => 'Имя получателя',
                'attachment.type' => 'Тип вложения', 'attachment.link' => 'Ссылка на вложение',
                'attachment.file_name' => 'Имя файла',
            ],
            'unsorted' => ['uid' => 'ID неразобранного', 'category' => 'Категория', 'pipeline_id' => 'ID воронки', 'source' => 'Источник'],
            'chat_template_review' => [
                'id' => 'ID шаблона', 'type' => 'Тип шаблона', 'name' => 'Название шаблона',
                'content' => 'Содержимое шаблона', 'is_on_review' => 'На проверке',
                'waba_category' => 'Категория', 'waba_language' => 'Язык',
            ],
            default => [],
        };

        if ($action === 'responsible') $fields['old_responsible_user_id'] = 'Прежний ответственный';
        if ($action === 'update' && $entity === 'task') {
            $fields['action_close'] = 'Задача закрыта этим изменением';
            $fields['result.id'] = 'ID результата';
            $fields['result.text'] = 'Текст результата';
        }
        if ($action === 'status') {
            $fields['old_status_id'] = 'Прежний этап';
            $fields['old_pipeline_id'] = 'Прежняя воронка';
        }

        return $fields;
    }

    /** @return array<string, mixed> Nulls describe paths; they are never used as example values. */
    public static function shape(string $type): array
    {
        $shape = [];
        foreach (array_keys(self::fields($type)) as $path) {
            $cursor = &$shape;
            foreach (explode('.', $path) as $part) $cursor = &$cursor[$part];
            $cursor = null;
            unset($cursor);
        }
        return $shape;
    }

    /** @return array<string, array<string, string>> */
    public static function maskGroups(array $definition): array
    {
        $names = WorkflowExpressionCatalog::referenceNames($definition['actions'] ?? [], $definition);
        $groups = [];
        foreach (WorkflowStartNodes::all($definition) as $id => $start) {
            $fields = self::fields((string) ($start['type'] ?? ''));
            if ($fields === []) continue;
            $name = $names[$id] ?? 'Событие';
            $reference = '$('.json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).')';
            foreach ($fields as $path => $label) {
                $groups['Событие: '.$name]['{{ '.$reference.'.'.$path.' }}'] = $label;
            }
        }
        return $groups;
    }
}
