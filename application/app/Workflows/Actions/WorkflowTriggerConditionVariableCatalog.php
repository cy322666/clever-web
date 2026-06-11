<?php

namespace App\Workflows\Actions;

use App\Models\amoCRM\Field as AmoCrmField;
use App\Models\amoCRM\Staff as AmoCrmStaff;
use App\Models\amoCRM\Status as AmoCrmStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class WorkflowTriggerConditionVariableCatalog
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(bool $includeStaticValues = false): array
    {
        $options = array_replace_recursive(static::groupedMaskOptions(), static::amoCrmCustomFieldOptions());

        if ($includeStaticValues) {
            $options['Готовые значения'] = static::staticValueOptions();
        }

        return $options;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function groupedMaskOptions(): array
    {
        return [
            'Событие' => [
                '{{source}}' => 'Источник',
                '{{event}}' => 'Код события',
                '{{entity}}' => 'Тип сущности',
                '{{action}}' => 'Тип действия',
                '{{received_at}}' => 'Дата получения',
                '{{now}}' => 'Текущая дата и время',
            ],
            'Текущая сущность' => [
                '{{item.id}}' => 'ID',
                '{{item.name}}' => 'Название / имя',
                '{{item.responsible_user_id}}' => 'Ответственный',
                '{{item.created_user_id}}' => 'Кто создал',
                '{{item.updated_user_id}}' => 'Кто изменил',
                '{{item.created_at}}' => 'Дата создания',
                '{{item.updated_at}}' => 'Дата изменения',
                '{{item.account_id}}' => 'ID аккаунта',
                '{{item.group_id}}' => 'ID группы',
                '{{item.tags}}' => 'Теги',
            ],
            'Сделка' => [
                '{{lead.id}}' => 'ID сделки',
                '{{lead.name}}' => 'Название сделки',
                '{{lead.price}}' => 'Бюджет',
                '{{lead.pipeline_id}}' => 'Воронка',
                '{{lead.status_id}}' => 'Статус',
                '{{lead.responsible_user_id}}' => 'Ответственный',
                '{{lead.created_user_id}}' => 'Кто создал',
                '{{lead.updated_user_id}}' => 'Кто изменил',
                '{{lead.created_at}}' => 'Дата создания',
                '{{lead.updated_at}}' => 'Дата изменения',
                '{{lead.closest_task_at}}' => 'Ближайшая задача',
                '{{lead.tags}}' => 'Теги сделки',
            ],
            'Смена статуса сделки' => [
                '{{status.old_status_id}}' => 'Старый статус',
                '{{status.status_id}}' => 'Новый статус',
                '{{status.old_pipeline_id}}' => 'Старая воронка',
                '{{status.pipeline_id}}' => 'Новая воронка',
                '{{status.responsible_user_id}}' => 'Ответственный',
                '{{status.updated_at}}' => 'Дата смены статуса',
            ],
            'Смена ответственного' => [
                '{{responsible.old_responsible_user_id}}' => 'Старый ответственный',
                '{{responsible.responsible_user_id}}' => 'Новый ответственный',
                '{{responsible.updated_at}}' => 'Дата смены ответственного',
            ],
            'Контакт' => [
                '{{contact.id}}' => 'ID контакта',
                '{{contact.name}}' => 'Имя контакта',
                '{{contact.type}}' => 'Тип',
                '{{contact.responsible_user_id}}' => 'Ответственный',
                '{{contact.created_user_id}}' => 'Кто создал',
                '{{contact.updated_user_id}}' => 'Кто изменил',
                '{{contact.created_at}}' => 'Дата создания',
                '{{contact.updated_at}}' => 'Дата изменения',
                '{{contact.tags}}' => 'Теги контакта',
            ],
            'Компания' => [
                '{{company.id}}' => 'ID компании',
                '{{company.name}}' => 'Название компании',
                '{{company.type}}' => 'Тип',
                '{{company.responsible_user_id}}' => 'Ответственный',
                '{{company.created_user_id}}' => 'Кто создал',
                '{{company.updated_user_id}}' => 'Кто изменил',
                '{{company.created_at}}' => 'Дата создания',
                '{{company.updated_at}}' => 'Дата изменения',
                '{{company.tags}}' => 'Теги компании',
            ],
            'Покупатель' => [
                '{{customer.id}}' => 'ID покупателя',
                '{{customer.name}}' => 'Название покупателя',
                '{{customer.next_date}}' => 'Следующая покупка',
                '{{customer.periodicity}}' => 'Периодичность',
                '{{customer.responsible_user_id}}' => 'Ответственный',
                '{{customer.created_at}}' => 'Дата создания',
                '{{customer.updated_at}}' => 'Дата изменения',
            ],
            'Задача' => [
                '{{task.id}}' => 'ID задачи',
                '{{task.element_id}}' => 'ID связанной сущности',
                '{{task.element_type}}' => 'Тип связанной сущности',
                '{{task.task_type}}' => 'Тип задачи',
                '{{task.text}}' => 'Текст задачи',
                '{{task.complete_till}}' => 'Срок выполнения',
                '{{task.responsible_user_id}}' => 'Ответственный',
                '{{task.created_at}}' => 'Дата создания',
                '{{task.updated_at}}' => 'Дата изменения',
            ],
            'Примечание' => [
                '{{note.id}}' => 'ID примечания',
                '{{note.element_id}}' => 'ID сущности',
                '{{note.element_type}}' => 'Тип сущности',
                '{{note.note_type}}' => 'Тип примечания',
                '{{note.text}}' => 'Текст примечания',
                '{{note.responsible_user_id}}' => 'Ответственный',
                '{{note.created_at}}' => 'Дата создания',
            ],
            'Беседа / чат' => [
                '{{talk.id}}' => 'ID беседы',
                '{{talk.talk_id}}' => 'ID чата',
                '{{talk.entity_id}}' => 'ID сущности',
                '{{talk.entity_type}}' => 'Тип сущности',
                '{{talk.created_at}}' => 'Дата создания',
                '{{chat_template_review.id}}' => 'ID WhatsApp-шаблона',
                '{{chat_template_review.status}}' => 'Статус WhatsApp-шаблона',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function staticValueOptions(): array
    {
        return [
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

    /**
     * @return array<string, array<string, string>>
     */
    public static function groupedAmoFieldOptions(): array
    {
        return static::amoCrmCustomFieldOptions();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function amoCrmCustomFieldOptions(): array
    {
        $userId = Auth::id();

        if (!$userId) {
            return [];
        }

        $entityMap = [
            'leads' => ['key' => 'lead', 'group' => 'Поля сделки'],
            'contacts' => ['key' => 'contact', 'group' => 'Поля контакта'],
            'companies' => ['key' => 'company', 'group' => 'Поля компании'],
            'customers' => ['key' => 'customer', 'group' => 'Поля покупателя'],
        ];

        $fields = AmoCrmField::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->whereNotNull('field_id')
            ->whereIn('entity_type', array_keys($entityMap))
            ->orderBy('entity_type')
            ->orderBy('name')
            ->get(['entity_type', 'field_id', 'name', 'code']);

        $options = [];

        foreach ($fields as $field) {
            $meta = $entityMap[(string)$field->entity_type] ?? null;

            if ($meta === null) {
                continue;
            }

            $fieldId = trim((string)$field->field_id);

            if ($fieldId === '') {
                continue;
            }

            $label = trim((string)($field->name ?: $field->code ?: $fieldId));
            $options[$meta['group']]['{{' . $meta['key'] . '.cf(' . $fieldId . ')}}'] = $label . ' · ID ' . $fieldId;
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
    public static function flatMaskOptions(): array
    {
        return collect(static::groupedMaskOptions())
            ->flatMap(fn(array $options): array => $options)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function flatAmoFieldOptions(): array
    {
        return collect(static::groupedAmoFieldOptions())
            ->flatMap(fn(array $options): array => $options)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function search(string $query, bool $includeStaticValues = false): array
    {
        return static::searchInOptions(static::flatOptions($includeStaticValues), $query, true);
    }

    /**
     * @return array<string, string>
     */
    public static function searchMasks(string $query): array
    {
        return static::searchInOptions(static::flatMaskOptions(), $query);
    }

    /**
     * @return array<string, string>
     */
    public static function searchAmoFields(string $query): array
    {
        return static::searchInOptions(static::flatAmoFieldOptions(), $query);
    }

    /**
     * @return array<string, string>
     */
    private static function searchInOptions(array $options, string $query, bool $allowCustom = false): array
    {
        $query = trim($query);

        if ($query === '') {
            return $options;
        }

        $results = collect($options)
            ->filter(fn(string $label, string $value): bool => Str::contains(
                Str::lower($label . ' ' . $value),
                Str::lower($query),
            ))
            ->all();

        if ($allowCustom && !array_key_exists($query, $results)) {
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

    /**
     * @return array<string, array<int, array{id: string, name: string, subtitle: string}>>
     */
    public static function systemIdGroups(): array
    {
        $userId = Auth::id();

        if (!$userId) {
            return [];
        }

        return array_filter([
            'Поля amoCRM' => static::amoFieldIdItems($userId),
            'Воронки amoCRM' => static::amoPipelineIdItems($userId),
            'Этапы amoCRM' => static::amoStatusIdItems($userId),
            'Группы пользователей amoCRM' => static::amoStaffGroupIdItems($userId),
            'Ответственные amoCRM' => static::amoStaffIdItems($userId),
        ]);
    }

    /**
     * @return array<int, array{id: string, name: string, subtitle: string}>
     */
    private static function amoFieldIdItems(int $userId): array
    {
        return AmoCrmField::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->whereNotNull('field_id')
            ->orderBy('entity_type')
            ->orderBy('name')
            ->get(['field_id', 'name', 'type', 'code', 'entity_type'])
            ->map(fn(AmoCrmField $field): array => [
                'id' => (string)$field->field_id,
                'name' => (string)($field->name ?: $field->code ?: $field->field_id),
                'entity' => static::entityTypeLabel((string)$field->entity_type),
                'subtitle' => static::entityTypeLabel((string)$field->entity_type)
                    . ' · ' . trim((string)($field->type ?: 'поле'))
                    . ($field->code ? ' · ' . $field->code : ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string, subtitle: string}>
     */
    private static function amoPipelineIdItems(int $userId): array
    {
        return AmoCrmStatus::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('is_archive', false)
            ->whereNotNull('pipeline_id')
            ->orderBy('pipeline_name')
            ->get(['pipeline_id', 'pipeline_name'])
            ->unique('pipeline_id')
            ->map(fn(AmoCrmStatus $status): array => [
                'id' => (string)$status->pipeline_id,
                'name' => (string)($status->pipeline_name ?: 'Воронка ' . $status->pipeline_id),
                'subtitle' => 'ID воронки',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string, subtitle: string}>
     */
    private static function amoStatusIdItems(int $userId): array
    {
        return AmoCrmStatus::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('is_archive', false)
            ->whereNotNull('status_id')
            ->orderBy('pipeline_name')
            ->orderBy('sort')
            ->get(['status_id', 'name', 'pipeline_id', 'pipeline_name'])
            ->map(fn(AmoCrmStatus $status): array => [
                'id' => (string)$status->status_id,
                'name' => (string)($status->name ?: 'Этап ' . $status->status_id),
                'subtitle' => (string)($status->pipeline_name ?: 'Воронка'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string, subtitle: string}>
     */
    private static function amoStaffIdItems(int $userId): array
    {
        return AmoCrmStaff::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->whereNotNull('staff_id')
            ->orderBy('name')
            ->get(['staff_id', 'name', 'group_name'])
            ->map(fn(AmoCrmStaff $staff): array => [
                'id' => (string)$staff->staff_id,
                'name' => (string)($staff->name ?: 'Пользователь ' . $staff->staff_id),
                'subtitle' => (string)($staff->group_name ?: 'Ответственный'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string, subtitle: string}>
     */
    private static function amoStaffGroupIdItems(int $userId): array
    {
        return AmoCrmStaff::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->whereNotNull('group_id')
            ->where('group_id', '>', 0)
            ->orderBy('group_name')
            ->get(['group_id', 'group_name'])
            ->unique('group_id')
            ->map(fn(AmoCrmStaff $staff): array => [
                'id' => (string)$staff->group_id,
                'name' => (string)($staff->group_name ?: 'Группа ' . $staff->group_id),
                'subtitle' => 'Группа пользователей',
            ])
            ->values()
            ->all();
    }

    private static function entityTypeLabel(string $entityType): string
    {
        return [
            'leads' => 'Сделка',
            'contacts' => 'Контакт',
            'companies' => 'Компания',
            'customers' => 'Покупатель',
        ][$entityType] ?? $entityType;
    }
}
