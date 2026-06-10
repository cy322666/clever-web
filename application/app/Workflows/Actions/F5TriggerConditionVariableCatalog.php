<?php

namespace App\Workflows\Actions;

use Illuminate\Support\Str;

class F5TriggerConditionVariableCatalog
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(bool $includeStaticValues = false): array
    {
        $options = [
            'Событие' => [
                '{{trigger.source}}' => 'Источник',
                '{{trigger.event}}' => 'Код события',
                '{{trigger.entity}}' => 'Тип сущности',
                '{{trigger.action}}' => 'Тип действия',
                '{{trigger.received_at}}' => 'Дата получения',
                '{{now}}' => 'Текущая дата и время',
            ],
            'Текущая сущность' => [
                '{{trigger.item.id}}' => 'ID',
                '{{trigger.item.name}}' => 'Название / имя',
                '{{trigger.item.responsible_user_id}}' => 'Ответственный',
                '{{trigger.item.created_user_id}}' => 'Кто создал',
                '{{trigger.item.updated_user_id}}' => 'Кто изменил',
                '{{trigger.item.created_at}}' => 'Дата создания',
                '{{trigger.item.updated_at}}' => 'Дата изменения',
                '{{trigger.item.account_id}}' => 'ID аккаунта',
                '{{trigger.item.group_id}}' => 'ID группы',
                '{{trigger.item.tags}}' => 'Теги',
                '{{trigger.item.custom_fields_values}}' => 'Дополнительные поля',
            ],
            'Сделка' => [
                '{{trigger.lead.id}}' => 'ID сделки',
                '{{trigger.lead.name}}' => 'Название сделки',
                '{{trigger.lead.price}}' => 'Бюджет',
                '{{trigger.lead.pipeline_id}}' => 'Воронка',
                '{{trigger.lead.status_id}}' => 'Статус',
                '{{trigger.lead.responsible_user_id}}' => 'Ответственный',
                '{{trigger.lead.created_user_id}}' => 'Кто создал',
                '{{trigger.lead.updated_user_id}}' => 'Кто изменил',
                '{{trigger.lead.created_at}}' => 'Дата создания',
                '{{trigger.lead.updated_at}}' => 'Дата изменения',
                '{{trigger.lead.closest_task_at}}' => 'Ближайшая задача',
                '{{trigger.lead.tags}}' => 'Теги сделки',
                '{{trigger.lead.custom_fields_values}}' => 'Поля сделки',
            ],
            'Смена статуса сделки' => [
                '{{trigger.status.old_status_id}}' => 'Старый статус',
                '{{trigger.status.status_id}}' => 'Новый статус',
                '{{trigger.status.old_pipeline_id}}' => 'Старая воронка',
                '{{trigger.status.pipeline_id}}' => 'Новая воронка',
                '{{trigger.status.responsible_user_id}}' => 'Ответственный',
                '{{trigger.status.updated_at}}' => 'Дата смены статуса',
            ],
            'Смена ответственного' => [
                '{{trigger.responsible.old_responsible_user_id}}' => 'Старый ответственный',
                '{{trigger.responsible.responsible_user_id}}' => 'Новый ответственный',
                '{{trigger.responsible.updated_at}}' => 'Дата смены ответственного',
            ],
            'Контакт' => [
                '{{trigger.contact.id}}' => 'ID контакта',
                '{{trigger.contact.name}}' => 'Имя контакта',
                '{{trigger.contact.type}}' => 'Тип',
                '{{trigger.contact.responsible_user_id}}' => 'Ответственный',
                '{{trigger.contact.created_user_id}}' => 'Кто создал',
                '{{trigger.contact.updated_user_id}}' => 'Кто изменил',
                '{{trigger.contact.created_at}}' => 'Дата создания',
                '{{trigger.contact.updated_at}}' => 'Дата изменения',
                '{{trigger.contact.tags}}' => 'Теги контакта',
                '{{trigger.contact.custom_fields_values}}' => 'Поля контакта',
            ],
            'Компания' => [
                '{{trigger.company.id}}' => 'ID компании',
                '{{trigger.company.name}}' => 'Название компании',
                '{{trigger.company.type}}' => 'Тип',
                '{{trigger.company.responsible_user_id}}' => 'Ответственный',
                '{{trigger.company.created_user_id}}' => 'Кто создал',
                '{{trigger.company.updated_user_id}}' => 'Кто изменил',
                '{{trigger.company.created_at}}' => 'Дата создания',
                '{{trigger.company.updated_at}}' => 'Дата изменения',
                '{{trigger.company.tags}}' => 'Теги компании',
                '{{trigger.company.custom_fields_values}}' => 'Поля компании',
            ],
            'Покупатель' => [
                '{{trigger.customer.id}}' => 'ID покупателя',
                '{{trigger.customer.name}}' => 'Название покупателя',
                '{{trigger.customer.next_date}}' => 'Следующая покупка',
                '{{trigger.customer.periodicity}}' => 'Периодичность',
                '{{trigger.customer.responsible_user_id}}' => 'Ответственный',
                '{{trigger.customer.created_at}}' => 'Дата создания',
                '{{trigger.customer.updated_at}}' => 'Дата изменения',
            ],
            'Задача' => [
                '{{trigger.task.id}}' => 'ID задачи',
                '{{trigger.task.element_id}}' => 'ID связанной сущности',
                '{{trigger.task.element_type}}' => 'Тип связанной сущности',
                '{{trigger.task.task_type}}' => 'Тип задачи',
                '{{trigger.task.text}}' => 'Текст задачи',
                '{{trigger.task.complete_till}}' => 'Срок выполнения',
                '{{trigger.task.responsible_user_id}}' => 'Ответственный',
                '{{trigger.task.created_at}}' => 'Дата создания',
                '{{trigger.task.updated_at}}' => 'Дата изменения',
            ],
            'Примечание' => [
                '{{trigger.note.id}}' => 'ID примечания',
                '{{trigger.note.element_id}}' => 'ID сущности',
                '{{trigger.note.element_type}}' => 'Тип сущности',
                '{{trigger.note.note_type}}' => 'Тип примечания',
                '{{trigger.note.text}}' => 'Текст примечания',
                '{{trigger.note.responsible_user_id}}' => 'Ответственный',
                '{{trigger.note.created_at}}' => 'Дата создания',
            ],
            'Беседа / чат' => [
                '{{trigger.talk.id}}' => 'ID беседы',
                '{{trigger.talk.talk_id}}' => 'ID чата',
                '{{trigger.talk.entity_id}}' => 'ID сущности',
                '{{trigger.talk.entity_type}}' => 'Тип сущности',
                '{{trigger.talk.created_at}}' => 'Дата создания',
                '{{trigger.chat_template_review.id}}' => 'ID WhatsApp-шаблона',
                '{{trigger.chat_template_review.status}}' => 'Статус WhatsApp-шаблона',
            ],
        ];

        if ($includeStaticValues) {
            $options['Готовые значения'] = [
                'true' => 'Да / истина',
                'false' => 'Нет / ложь',
                '1' => '1',
                '0' => '0',
                'lead' => 'Сделка',
                'contact' => 'Контакт',
                'company' => 'Компания',
                'customer' => 'Покупатель',
                'task' => 'Задача',
            ];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function flatOptions(bool $includeStaticValues = false): array
    {
        return collect(static::groupedOptions($includeStaticValues))
            ->flatMap(fn(array $options): array => $options)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function search(string $query, bool $includeStaticValues = false): array
    {
        $query = trim($query);

        if ($query === '') {
            return static::flatOptions($includeStaticValues);
        }

        $results = collect(static::flatOptions($includeStaticValues))
            ->filter(fn(string $label, string $value): bool => Str::contains(
                Str::lower($label . ' ' . $value),
                Str::lower($query),
            ))
            ->all();

        if (!array_key_exists($query, $results)) {
            $results[$query] = 'Своё значение: ' . $query;
        }

        return $results;
    }

    public static function label(?string $value, bool $includeStaticValues = false): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return static::flatOptions($includeStaticValues)[$value] ?? $value;
    }
}
