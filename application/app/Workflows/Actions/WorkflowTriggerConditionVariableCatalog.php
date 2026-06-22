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
        $options = array_replace_recursive(
            static::groupedMaskOptions(),
            static::amoCrmCustomFieldOptions(),
            static::groupedAmoPipelineOptions(),
            static::groupedAmoStatusOptions(),
        );

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
            'Быстрые значения' => [
                '{{item.id}}' => 'ID текущей сущности',
                '{{item.name}}' => 'Название / имя текущей сущности',
                '{{item.responsible_user_id}}' => 'Текущий ответственный',
                '{{item.created_user_id}}' => 'Создавший пользователь',
                '{{item.updated_user_id}}' => 'Изменивший пользователь',
                '{{item.tags}}' => 'Теги текущей сущности',
            ],
            'Вебхук' => [
                '{{payload}}' => 'Тело запроса',
                '{{payload.key}}' => 'Значение из тела запроса',
                '{{query.key}}' => 'Значение из query-параметров',
                '{{headers.content_type}}' => 'Заголовок Content-Type',
                '{{headers.user_agent}}' => 'Заголовок User-Agent',
                '{{method}}' => 'HTTP-метод',
                '{{url}}' => 'URL запроса',
                '{{ip}}' => 'IP отправителя',
                '{{raw_body}}' => 'Сырое тело запроса',
            ],
            'Дата / время' => [
                '{{current.date}}' => 'Текущая дата',
                '{{current.time}}' => 'Текущее время',
                '{{current.datetime}}' => 'Текущие дата и время',
                '{{current.weekday}}' => 'Текущий день недели',
                '{{current.weekday_number}}' => 'Номер текущего дня недели',
                '{{item.created_at}}' => 'Дата создания',
                '{{item.updated_at}}' => 'Дата изменения',
                '{{item.closest_task_at}}' => 'Дата ближайшей задачи',
                '{{lead.created_at}}' => 'Дата создания сделки',
                '{{lead.updated_at}}' => 'Дата изменения сделки',
                '{{lead.closest_task_at}}' => 'Дата ближайшей задачи сделки',
            ],
            'Пользователь' => [
                '{{item.created_user_id}}' => 'Создавший пользователь',
                '{{item.updated_user_id}}' => 'Изменивший пользователь',
                '{{item.responsible_user_id}}' => 'Текущий ответственный',
                '{{lead.responsible_user_id}}' => 'Ответственный сделки',
                '{{contact.responsible_user_id}}' => 'Ответственный контакта',
                '{{company.responsible_user_id}}' => 'Ответственный компании',
                '{{customer.responsible_user_id}}' => 'Ответственный покупателя',
                '{{status.responsible_user_id}}' => 'Ответственный при смене статуса',
                '{{responsible.old_responsible_user_id}}' => 'Старый ответственный',
                '{{responsible.responsible_user_id}}' => 'Новый ответственный',
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
                '{{lead.open_tasks_count}}' => 'Кол-во открытых задач сделки',
                '{{lead.overdue_tasks_count}}' => 'Кол-во просроченных задач сделки',
                '{{lead.tasks_count}}' => 'Количество задач сделки',
                '{{contact.open_tasks_count}}' => 'Кол-во открытых задач контакта',
                '{{company.open_tasks_count}}' => 'Кол-во открытых задач компании',
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
            'Счетчики' => [
                '{{lead.open_tasks_count}}' => 'Кол-во открытых задач',
                '{{lead.overdue_tasks_count}}' => 'Кол-во просроченных задач',
                '{{lead.tasks_count}}' => 'Количество задач',
                '{{lead.contacts_count}}' => 'Количество контактов',
                '{{lead.notes_count}}' => 'Количество примечаний',
                '{{contact.leads_count}}' => 'Количество сделок контакта',
                '{{company.leads_count}}' => 'Количество сделок компании',
                '{{company.contacts_count}}' => 'Количество контактов компании',
                '{{item.tags_count}}' => 'Количество тегов',
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
    public static function groupedAmoPipelineOptions(): array
    {
        $userId = Auth::id();

        if (!$userId) {
            return [];
        }

        $pipelines = AmoCrmStatus::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('is_archive', false)
            ->whereNotNull('pipeline_id')
            ->orderBy('pipeline_name')
            ->get(['pipeline_id', 'pipeline_name'])
            ->unique('pipeline_id')
            ->mapWithKeys(static fn(AmoCrmStatus $status): array => [
                (string)$status->pipeline_id => (string)($status->pipeline_name ?: 'Воронка ' . $status->pipeline_id),
            ])
            ->all();

        return $pipelines === [] ? [] : ['Воронка' => $pipelines];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function groupedAmoStatusOptions(): array
    {
        $userId = Auth::id();

        if (!$userId) {
            return [];
        }

        $options = [];

        $statuses = AmoCrmStatus::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('is_archive', false)
            ->whereNotNull('status_id')
            ->orderBy('pipeline_name')
            ->orderBy('sort')
            ->get(['status_id', 'name', 'pipeline_name']);

        foreach ($statuses as $status) {
            $pipelineName = (string)($status->pipeline_name ?: 'Без воронки');
            $statusId = trim((string)$status->status_id);

            if ($statusId === '') {
                continue;
            }

            $options[$pipelineName][$statusId] = (string)($status->name ?: 'Этап ' . $statusId);
        }

        return $options;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function groupedAmoStatusConditionOptions(): array
    {
        $userId = Auth::id();

        if (!$userId) {
            return [];
        }

        $options = [];

        $statuses = AmoCrmStatus::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('is_archive', false)
            ->whereNotNull('pipeline_id')
            ->whereNotNull('status_id')
            ->orderBy('pipeline_name')
            ->orderBy('sort')
            ->get(['pipeline_id', 'status_id', 'name', 'pipeline_name']);

        foreach ($statuses as $status) {
            $pipelineId = trim((string)$status->pipeline_id);
            $statusId = trim((string)$status->status_id);

            if ($pipelineId === '' || $statusId === '') {
                continue;
            }

            $pipelineName = (string)($status->pipeline_name ?: 'Без воронки');
            $options[$pipelineName][$pipelineId . '.' . $statusId] = (string)($status->name ?: 'Этап ' . $statusId);
        }

        return $options;
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
    public static function flatAmoPipelineOptions(): array
    {
        return collect(static::groupedAmoPipelineOptions())
            ->flatMap(fn(array $options): array => $options)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function flatAmoStatusOptions(): array
    {
        return collect(static::groupedAmoStatusOptions())
            ->flatMap(fn(array $options): array => $options)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function flatAmoStatusConditionOptions(): array
    {
        return collect(static::groupedAmoStatusConditionOptions())
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
    public static function searchAmoPipelines(string $query): array
    {
        return static::searchInOptions(static::flatAmoPipelineOptions(), $query);
    }

    /**
     * @return array<string, string>
     */
    public static function searchAmoStatuses(string $query): array
    {
        return static::searchInOptions(static::flatAmoStatusOptions(), $query);
    }

    /**
     * @return array<string, string>
     */
    public static function searchAmoStatusConditions(string $query): array
    {
        return static::searchInOptions(static::flatAmoStatusConditionOptions(), $query);
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

        return static::flatOptions($includeStaticValues)[$value]
            ?? static::amoPipelineLabel($value)
            ?? static::amoStatusLabel($value)
            ?? $value;
    }

    public static function amoPipelineLabel(string $pipelineId): ?string
    {
        $label = static::flatAmoPipelineOptions()[$pipelineId] ?? null;

        return $label !== null ? 'Воронка: ' . $label : null;
    }

    public static function amoStatusLabel(string $statusId): ?string
    {
        $label = static::amoStatusName($statusId);

        return $label !== null ? 'Этап: ' . $label : null;
    }

    public static function amoStatusName(string $statusId, mixed $pipelineId = null): ?string
    {
        if ($statusId === '') {
            return null;
        }

        $pipelineId = filled($pipelineId) ? (string)$pipelineId : null;
        $cacheKey = (string)Auth::id() . ':' . ($pipelineId ?? '*') . ':' . $statusId;

        static $cache = [];

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        if ($pipelineId === null) {
            $cache[$cacheKey] = static::flatAmoStatusOptions()[$statusId] ?? null;

            return $cache[$cacheKey];
        }

        $status = AmoCrmStatus::query()
            ->where('user_id', Auth::id())
            ->where('active', true)
            ->where('is_archive', false)
            ->where('pipeline_id', $pipelineId)
            ->where('status_id', $statusId)
            ->first(['name']);

        $cache[$cacheKey] = filled($status?->name) ? (string)$status->name : null;

        return $cache[$cacheKey];
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
            'Сделка' => static::amoEntityVariableItems('Сделка'),
            'Контакт' => static::amoEntityVariableItems('Контакт'),
            'Компания' => static::amoEntityVariableItems('Компания'),
            'Покупатель' => static::amoEntityVariableItems('Покупатель'),
            'Задача' => static::amoEntityVariableItems('Задача'),
            'Примечание' => static::amoEntityVariableItems('Примечание'),
            'Беседа / чат' => static::amoEntityVariableItems('Беседа / чат'),
            'Поля' => static::amoFieldIdItems($userId),
            'Воронки' => static::amoPipelineIdItems($userId),
            'Этапы' => static::amoStatusIdItems($userId),
            'Группы пользователей' => static::amoStaffGroupIdItems($userId),
            'Ответственные' => static::amoStaffIdItems($userId),
        ]);
    }

    /**
     * @return array<int, array{id: string, name: string, subtitle: string, kind: string}>
     */
    private static function amoEntityVariableItems(string $group): array
    {
        return collect(static::groupedMaskOptions()[$group] ?? [])
            ->map(fn(string $label, string $mask): array => [
                'id' => $mask,
                'name' => $label,
                'subtitle' => 'Переменная',
                'kind' => 'variable',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string, subtitle: string, entity: string, options: array<int, array{id: string, name: string}>}>
     */
    private static function amoFieldIdItems(int $userId): array
    {
        return AmoCrmField::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->whereNotNull('field_id')
            ->orderBy('entity_type')
            ->orderBy('name')
            ->get(['field_id', 'name', 'type', 'code', 'entity_type', 'enums'])
            ->map(fn(AmoCrmField $field): array => [
                'id' => (string)$field->field_id,
                'name' => (string)($field->name ?: $field->code ?: $field->field_id),
                'entity' => static::entityTypeLabel((string)$field->entity_type),
                'subtitle' => static::entityTypeLabel((string)$field->entity_type)
                    . ' · ' . trim((string)($field->type ?: 'поле'))
                    . ($field->code ? ' · ' . $field->code : ''),
                'options' => static::amoFieldEnumItems($field->enums),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private static function amoFieldEnumItems(mixed $enums): array
    {
        if (is_string($enums)) {
            $enums = json_decode($enums, true);
        }

        if (!is_array($enums)) {
            return [];
        }

        return collect($enums)
            ->map(function (mixed $enum, int|string $key): ?array {
                if (is_array($enum)) {
                    $id = trim((string)($enum['id'] ?? $key));
                    $name = trim((string)($enum['value'] ?? $enum['name'] ?? ''));
                } else {
                    $id = trim((string)$key);
                    $name = trim((string)$enum);
                }

                if ($id === '' || $name === '') {
                    return null;
                }

                return [
                    'id' => $id,
                    'name' => $name,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string, subtitle: string, options: array<int, array{id: string, name: string}>}>
     */
    private static function amoPipelineIdItems(int $userId): array
    {
        $statuses = AmoCrmStatus::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('is_archive', false)
            ->whereNotNull('pipeline_id')
            ->orderBy('pipeline_name')
            ->orderBy('sort')
            ->get(['pipeline_id', 'pipeline_name', 'status_id', 'name']);

        $statusesByPipeline = $statuses
            ->whereNotNull('status_id')
            ->groupBy(fn(AmoCrmStatus $status): string => (string)$status->pipeline_id);

        return $statuses
            ->unique('pipeline_id')
            ->map(fn(AmoCrmStatus $status): array => [
                'id' => (string)$status->pipeline_id,
                'name' => (string)($status->pipeline_name ?: 'Воронка ' . $status->pipeline_id),
                'subtitle' => 'ID воронки',
                'options' => $statusesByPipeline
                    ->get((string)$status->pipeline_id, collect())
                    ->map(fn(AmoCrmStatus $pipelineStatus): array => [
                        'id' => (string)$pipelineStatus->status_id,
                        'name' => (string)($pipelineStatus->name ?: 'Этап ' . $pipelineStatus->status_id),
                    ])
                    ->values()
                    ->all(),
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
