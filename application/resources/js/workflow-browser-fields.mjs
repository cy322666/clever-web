import { getPath } from './workflow-browser-document.mjs';

const field = (path, label, options = {}) => ({ path, label, ...options });
const choice = (path, label, options, extra = {}) => field(path, label, { options, ...extra });
const text = (path, label, rows = 4) => field(path, label, { rows });
const repeat = (path, label, fields, defaults = {}, addLabel = 'Добавить') =>
    field(path, label, { fields, defaults, addLabel });
const conditions = {
    equals: 'Равно',
    not_equals: 'Не равно',
    strict_equals: 'Строго равно',
    contains: 'Содержит',
    not_contains: 'Не содержит',
    is_true: 'Да',
    is_false: 'Нет',
    is_empty: 'Пусто',
    is_not_empty: 'Не пусто',
    gt: 'Больше',
    lt: 'Меньше',
};
const unary = (operator) =>
    ['is_true', 'is_false', 'is_empty', 'is_not_empty', 'empty', 'not_empty'].includes(operator);
const entities = { lead: 'Сделка', contact: 'Контакт', company: 'Компания', customer: 'Покупатель' };
const optionsOf = (rows) => Object.fromEntries(rows.map((row) => [row.id, row.name]));

/** Portable field descriptions. Conditions/options are evaluated locally, not through PHP closures. */
export function nodeFields(node, refs = {}, operations = {}) {
    if (!node) return [];
    const c = node.config || {},
        type = node.type;
    const users = refs.users || {},
        pipelines = refs.pipelines || {};
    const status = (pipeline) =>
        optionsOf(
            (refs.statuses || []).filter((row) => !pipeline || String(row.pipeline_id) === String(pipeline)),
        );
    const pipelineFields = (prefix = '') => [
        choice(prefix + 'pipeline_id', 'Воронка', pipelines),
        choice(prefix + 'status_id', 'Этап', status(getPath(c, prefix + 'pipeline_id'))),
    ];
    const entity =
        c.target_entity ||
        c.entity ||
        (type.includes('contact') ? 'contact' : type.includes('company') ? 'company' : 'lead');
    const target = (fixed = false) => [
        ...(!fixed ? [choice('target_entity', 'Тип записи', entities)] : []),
        choice(
            'entity_source',
            '',
            { context: 'Из контекста', manual: 'Указать вручную' },
            { buttons: true, default: 'context' },
        ),
        field('target_entity_id', `ID ${entities[entity]?.toLowerCase() || 'записи'}`, {
            visible: () => c.entity_source === 'manual',
        }),
    ];
    const system = {
        name: 'Название',
        responsible_user_id: 'Ответственный',
        ...(entity === 'lead'
            ? {
                  price: 'Бюджет',
                  pipeline_id: 'Воронка',
                  status_id: 'Этап',
                  closed_at: 'Дата закрытия',
                  loss_reason_id: 'Причина отказа',
              }
            : {}),
        ...(entity === 'contact' ? { first_name: 'Имя', last_name: 'Фамилия' } : {}),
    };
    const customOptions = {
        ...Object.fromEntries(Object.entries(system).map(([key, label]) => ['system:' + key, label])),
        ...Object.fromEntries(
            (refs.fields || [])
                .filter(
                    (row) =>
                        row.entity ===
                        ({ lead: 'leads', contact: 'contacts', company: 'companies', customer: 'customers' }[
                            entity
                        ] || entity),
                )
                .map((row) => [row.id, row.name]),
        ),
    };
    const valueOptions = (row) => {
        if (row.field === 'system:responsible_user_id') return users;
        if (row.field === 'system:pipeline_id') return pipelines;
        if (row.field === 'system:status_id')
            return status(
                c.pipeline_id ||
                    c.standard_fields?.pipeline_id ||
                    (c.fields || []).find((field) => field.field === 'system:pipeline_id')?.value,
            );
        const custom = (refs.fields || []).find((field) => String(field.id) === String(row.field));
        if (custom?.type === 'checkbox') return { 0: 'Нет', 1: 'Да' };
        let enums = custom?.enums;
        if (typeof enums === 'string') {
            try {
                enums = JSON.parse(enums);
            } catch {
                enums = [];
            }
        }
        return Object.fromEntries((Array.isArray(enums) ? enums : []).map((item) => [item.id, item.value]));
    };
    const mappings = repeat(
        'fields',
        'Поля',
        [choice('field', 'Поле', customOptions), choice('value', 'Значение', valueOptions)],
        { field: '', value: '' },
        'Добавить поле',
    );
    const bodyMode = choice(
        'body_mode',
        '',
        { fields: 'Поля', json: 'JSON' },
        { buttons: true, default: 'fields' },
    );
    const jsonBody = text('json_body', 'JSON-тело', 8);
    const body = (fields) => [bodyMode, ...(c.body_mode === 'json' ? [jsonBody] : fields)];
    const filterFields = refs.query?.[c.operation]?.fields || {};
    const filters = repeat(
        'filters',
        'Фильтры',
        [
            choice(
                'field',
                'Поле',
                Object.fromEntries(Object.entries(filterFields).map(([key, value]) => [key, value.label])),
            ),
            choice('operator', 'Условие', (row) => ({
                eq: 'Равно',
                ...(['number', 'date'].includes(filterFields[row.field]?.type)
                    ? { from: 'От', to: 'До' }
                    : {}),
            })),
            choice('pipeline_id', 'Воронка', pipelines, { visible: (row) => row.field === 'statuses' }),
            choice('value', 'Значение', (row) =>
                row.field === 'responsible_user_id'
                    ? users
                    : row.field === 'pipeline_id'
                      ? pipelines
                      : row.field === 'statuses'
                        ? status(row.pipeline_id)
                        : filterFields[row.field]?.options || {},
            ),
        ],
        { field: '', operator: 'eq', value: '' },
        'Добавить фильтр',
    );

    if (type === 'workflow_javascript') return [text('javascript_code', 'JavaScript', 18)];
    if (type === 'http_request')
        return [
            choice(
                'method',
                'Метод',
                Object.fromEntries(
                    ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'].map((value) => [value, value]),
                ),
            ),
            field('url', 'URL'),
            field('headers', 'Заголовки', { headers: true }),
            choice('body_format', 'Формат тела', { json: 'JSON', form: 'Форма' }),
            ...(c.body_format === 'form'
                ? [
                      repeat(
                          'form_fields',
                          'Параметры формы',
                          [field('name', 'Параметр'), field('value', 'Значение')],
                          { name: '', value: '' },
                          'Добавить параметр',
                      ),
                  ]
                : [text('body', 'Тело запроса', 8)]),
            field('timeout', 'Таймаут, секунд'),
            field('allowed_status_codes', 'Допустимые коды ответа'),
        ];
    if (type === 'workflow_loop')
        return [
            field('items', 'Что подать в цикл'),
            choice('mode', 'Сколько элементов обработать', {
                all: 'Все элементы',
                once: 'Один раз: первый элемент',
                limit: 'Указать количество',
            }),
            field('limit', 'Количество проходов', { visible: () => c.mode === 'limit', number: true }),
        ];
    if (type === 'workflow_delay') return [field('seconds', 'Пауза, секунд', { number: true })];
    if (type === 'workflow_set_variable')
        return [
            repeat(
                'assignments',
                '',
                [field('name', 'Имя'), field('value', 'Значение')],
                { name: '', value: '' },
                'Добавить переменную',
            ),
        ];
    if (['condition', 'control-condition'].includes(type))
        return [
            choice('logic', '', { and: 'И', or: 'ИЛИ' }, { buttons: true }),
            repeat(
                'conditions',
                '',
                [
                    field('left', ''),
                    choice('operator', '', conditions),
                    field('right', '', { visible: (row) => !unary(row.operator) }),
                ],
                { left: '', operator: 'equals', right: '' },
                'Добавить условие',
            ),
        ];
    if (type === 'workflow_filter_list')
        return [
            field('items', 'Список'),
            choice('match', '', { all: 'И', any: 'ИЛИ' }, { buttons: true }),
            repeat(
                'rules',
                '',
                [
                    field('field', 'Поле элемента', { itemField: true }),
                    choice('operator', 'Условие', {
                        eq: 'Равно',
                        neq: 'Не равно',
                        gt: 'Больше',
                        gte: 'Не меньше',
                        lt: 'Меньше',
                        lte: 'Не больше',
                        contains: 'Содержит',
                        empty: 'Пусто',
                        not_empty: 'Не пусто',
                    }),
                    field('value', '', { visible: (row) => !unary(row.operator) }),
                ],
                { field: '', operator: 'eq', value: '' },
                'Добавить условие',
            ),
        ];
    if (type === 'telegram_send_message')
        return [
            choice('credential_id', 'Подключение Telegram', refs.telegram || {}),
            field('chat_id', 'Чат ID'),
            text('text', 'Сообщение', 6),
            choice('parse_mode', 'Формат', {
                plain: 'Обычный текст',
                HTML: 'HTML',
                MarkdownV2: 'Markdown V2',
            }),
        ];
    if (type === 'schedule')
        return [
            field('timezone', 'Часовой пояс'),
            repeat(
                'rules',
                'Расписание',
                [
                    choice('frequency', 'Повторять', {
                        minutes: 'Каждые несколько минут',
                        hourly: 'Каждый час',
                        daily: 'Каждый день',
                        weekly: 'Каждую неделю',
                        monthly: 'Каждый месяц',
                        custom: 'Cron',
                    }),
                    field('interval', 'Интервал, минут', {
                        number: true,
                        visible: (row) => row.frequency === 'minutes',
                    }),
                    field('time', 'Время', {
                        visible: (row) => ['daily', 'weekly', 'monthly'].includes(row.frequency),
                    }),
                    choice(
                        'day_of_week',
                        'День недели',
                        {
                            1: 'Понедельник',
                            2: 'Вторник',
                            3: 'Среда',
                            4: 'Четверг',
                            5: 'Пятница',
                            6: 'Суббота',
                            0: 'Воскресенье',
                        },
                        { visible: (row) => row.frequency === 'weekly' },
                    ),
                    field('day_of_month', 'День месяца', {
                        number: true,
                        visible: (row) => row.frequency === 'monthly',
                    }),
                    field('cron_expression', 'Cron', { visible: (row) => row.frequency === 'custom' }),
                ],
                { frequency: 'daily', time: '09:00' },
                'Добавить расписание',
            ),
        ];
    if (type === 'amocrm_read') {
        const operation = operations[c.operation] || {},
            builder = Boolean(refs.query?.[c.operation]);
        return [
            choice(
                'operation',
                'Режим запроса',
                operation.variants || { [c.operation]: operation.name || c.operation },
            ),
            ...['id', 'entity_id', 'pipeline_id', 'catalog_id']
                .filter((key) => operation.path?.includes('{' + key + '}'))
                .map((key) =>
                    field(
                        key,
                        {
                            id: 'ID',
                            entity_id: 'ID записи',
                            pipeline_id: 'ID воронки',
                            catalog_id: 'ID списка',
                        }[key],
                    ),
                ),
            ...(c.operation === 'custom' ? [field('request_path', 'Путь запроса')] : []),
            ...(builder
                ? [
                      choice(
                          'body_mode',
                          '',
                          {
                              builder: 'Конструктор',
                              fields: 'Параметры',
                              ...(c.body_mode === 'json' ? { json: 'Сохранённый запрос' } : {}),
                          },
                          { buttons: true },
                      ),
                  ]
                : []),
            ...(builder && c.body_mode === 'builder'
                ? [
                      field('query', 'Поиск'),
                      filters,
                      field('limit', 'На странице'),
                      field('page', 'Страница'),
                      choice('sort', 'Сортировать по', refs.query[c.operation].sorts),
                      choice('direction', 'Порядок', { asc: 'По возрастанию', desc: 'По убыванию' }),
                  ]
                : []),
            ...(c.body_mode === 'json' ? [text('json_body', 'Параметры запроса', 8)] : []),
            choice('with_preset', 'Добавить к ответу', operation.with || {}, { preset: true }),
            repeat(
                'parameters',
                'Параметры',
                [field('name', 'Параметр'), field('value', 'Значение')],
                { name: '', value: '' },
                'Добавить параметр',
            ),
        ];
    }
    if (type === 'amocrm_contact_leads')
        return [
            choice('source', 'Контакт', { contact: 'Вручную', lead: 'Контакт сделки' }, { buttons: true }),
            field('contact_id', 'ID контакта', { visible: () => c.source === 'contact' }),
            field('lead_id', 'ID исходной сделки', { visible: () => c.source !== 'contact' }),
            field('exclude_lead_id', 'Исключить сделку, ID'),
        ];
    if (type === 'amocrm_create_lead')
        return body([
            field('name', 'Название сделки'),
            field('price', 'Бюджет'),
            ...pipelineFields(),
            choice('responsible_user_id', 'Ответственный', users),
            field('tags', 'Теги'),
            field('contact_id', 'ID контакта'),
            field('company_id', 'ID компании'),
            mappings,
        ]);
    if (['amocrm_create_contact', 'amocrm_create_company'].includes(type))
        return body([
            field('name', type.includes('contact') ? 'Имя контакта' : 'Название компании'),
            choice('responsible_user_id', 'Ответственный', users),
            field('tags', 'Теги'),
            ...target(true),
            mappings,
        ]);
    if (
        type.startsWith('amocrm_update_') &&
        (type.includes('fields') ||
            ['amocrm_update_lead', 'amocrm_update_contact', 'amocrm_update_company'].includes(type))
    )
        return [
            ...target(type !== 'amocrm_update_entity_fields'),
            ...body([
                field('standard_fields.name', 'Название'),
                ...(entity === 'lead'
                    ? [field('standard_fields.price', 'Бюджет'), ...pipelineFields('standard_fields.')]
                    : []),
                choice('standard_fields.responsible_user_id', 'Ответственный', users),
                mappings,
            ]),
        ];
    if (type === 'amocrm_create_task')
        return body([
            ...target(),
            choice('responsible_user_id', 'Ответственный', users),
            choice('task_type_id', 'Тип задачи', refs.task_types || { 1: 'Звонок', 2: 'Встреча' }),
            field('complete_till', 'Срок выполнения'),
            text('text', 'Текст задачи'),
        ]);
    if (type === 'amocrm_add_note')
        return [
            ...target(),
            choice('is_system', 'Вид примечания', { 0: 'Обычное', 1: 'Системное' }),
            text('text', 'Текст примечания', 6),
        ];
    if (type === 'amocrm_change_lead_status') return [...target(true), ...pipelineFields()];
    if (type === 'amocrm_change_tags')
        return [
            ...target(),
            field('tags_to_add', 'Добавить теги'),
            field('tags_to_remove', 'Удалить теги'),
            field('remove_all', 'Удалить все теги', { boolean: true }),
        ];
    if (['amocrm_link_entity', 'amocrm_unlink_entity'].includes(type))
        return [
            ...target(),
            choice('linked_entity', 'Связать с', entities),
            field('linked_entity_id', 'ID связанной записи'),
        ];
    if (type === 'amocrm_start_salesbot')
        return [...target(), choice('bot_id', 'Salesbot', refs.salesbots || {})];
    if (
        ['generic-webhook', 'manual', 'amo-button', 'amo-bulk', 'digital-pipeline'].includes(type) ||
        type.startsWith('amocrm-')
    )
        return [];
    // Legacy/custom nodes remain fully editable, without dropping unknown settings.
    return Object.entries(c)
        .filter(([key]) => !key.startsWith('_') && !['true_actions', 'false_actions'].includes(key))
        .map(([key, value]) =>
            field(key, labels[key] || key, {
                boolean: typeof value === 'boolean',
                json: typeof value === 'object' && value !== null,
                rows: typeof value === 'object' ? 6 : undefined,
            }),
        );
}

const labels = {
    source: 'Источник',
    event: 'Событие',
    entity: 'Тип записи',
    entity_source: 'Источник записи',
    target_entity: 'Тип записи',
    target_entity_id: 'ID записи',
    mode: 'Режим',
    name: 'Название',
    text: 'Текст',
    value: 'Значение',
    key: 'Имя',
    fields: 'Поля',
    filters: 'Фильтры',
    query: 'Поиск',
    pipeline_id: 'Воронка',
    status_id: 'Этап',
    responsible_user_id: 'Ответственный',
    responsible_user_ids: 'Ответственные',
    distribution_queue_uuid: 'Очередь распределения',
    limit: 'На странице',
    page: 'Страница',
    sort: 'Сортировка',
    direction: 'Порядок',
    conditions: 'Условия',
    tags: 'Теги',
    delay: 'Отложенное выполнение',
    bot_id: 'Salesbot',
    contact_id: 'ID контакта',
    lead_id: 'ID сделки',
};
