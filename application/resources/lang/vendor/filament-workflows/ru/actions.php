<?php

$fallback = require base_path('vendor/leek/filament-workflows/resources/lang/en/actions.php');

return array_replace_recursive($fallback, [
    'common' => [
        'categories' => [
            'general' => 'Общее',
            'communication' => 'Коммуникации',
            'records' => 'Записи',
            'data' => 'Данные',
            'integration' => 'Интеграции',
            'control_flow' => 'Логика процесса',
        ],
        'fields' => [
            'context_key' => ['label' => 'Ключ контекста'],
            'field' => ['label' => 'Поле'],
            'value' => ['label' => 'Значение'],
            'new_value' => ['label' => 'Новое значение'],
            'operator' => ['label' => 'Оператор'],
            'model_type' => ['label' => 'Тип модели'],
            'record_id' => ['label' => 'ID записи'],
            'state_field' => ['label' => 'Поле состояния'],
        ],
        'sections' => [
            'options' => 'Параметры',
            'output' => 'Результат',
            'field_values' => 'Значения полей',
            'field_values_description' => 'Настройте значения для каждого поля',
            'example_output' => 'Пример результата',
            'filters' => 'Фильтры',
            'filters_description' => 'Отфильтруйте записи для обновления',
            'updates' => 'Обновления',
            'updates_description' => 'Значения полей для обновления',
        ],
        'options' => [
            'trigger_model' => 'Модель триггера',
            'trigger_model_with_model' => 'Модель триггера (:model)',
            'trigger_model_field' => 'Из поля модели триггера',
            'trigger_model_field_with_model' => 'Из поля :model',
            'from_context_variable' => 'Из переменной контекста',
            'static_value' => 'Статическое значение',
            'from_trigger' => 'Из триггера',
            'from_context' => 'Из контекста',
            'lookup' => 'Поиск',
            'enum' => 'Список',
            'date' => 'Дата',
            'selected_user' => 'выбранный пользователь',
            'recipient' => 'получатель',
            'email' => 'email',
            'field' => 'поле',
            'role' => 'роль',
            'records' => 'записи',
            'record' => 'запись',
            'trigger_model_lower' => 'модель триггера',
            'record_from_context' => 'запись из контекста',
            'unknown' => 'неизвестно',
        ],
        'operators' => [
            'equals' => 'Равно',
            'not_equals' => 'Не равно',
            'strict_equals' => 'Строго равно',
            'greater_than' => 'Больше',
            'greater_than_or_equal' => 'Больше или равно',
            'less_than' => 'Меньше',
            'less_than_or_equal' => 'Меньше или равно',
            'contains' => 'Содержит',
            'not_contains' => 'Не содержит',
            'starts_with' => 'Начинается с',
            'ends_with' => 'Заканчивается на',
            'in_array' => 'В массиве',
            'not_in_array' => 'Не в массиве',
            'in_list' => 'В списке',
            'not_in_list' => 'Не в списке',
            'is_empty' => 'Пусто',
            'is_not_empty' => 'Не пусто',
            'is_null' => 'Null',
            'is_not_null' => 'Не null',
            'is_true' => 'Истина',
            'is_false' => 'Ложь',
            'matches_regex' => 'Соответствует regex',
        ],
        'messages' => [
            'variable_suggestions' => 'Введите :variable для подсказок переменных',
            'access_via' => 'Доступ через :variable',
        ],
    ],
    'send_email' => [
        'name' => 'Отправить email',
        'description' => 'Отправить письмо пользователю или на email-адрес',
        'fields' => [
            'recipient_type' => ['label' => 'Кому отправить'],
            'recipient_email' => ['label' => 'Email-адрес'],
            'recipient_user_id' => ['label' => 'Пользователь'],
            'recipient_field' => [
                'label' => 'Поле',
                'helper' => 'Выберите поле, где хранится email получателя',
            ],
            'subject' => ['label' => 'Тема'],
            'body' => ['label' => 'Текст письма'],
            'reply_to' => [
                'label' => 'Email для ответа (необязательно)',
                'helper' => 'Дополнительный email для ответа',
            ],
        ],
        'options' => [
            'specific_email_address' => 'Конкретный email',
            'specific_user' => 'Конкретный пользователь',
            'email' => 'Email',
        ],
        'configured' => [
            'summary' => 'Тема: <strong>:subject</strong> | Кому: <strong>:recipient</strong>',
        ],
        'validation' => [
            'subject_required' => 'Укажите тему письма',
            'body_required' => 'Укажите текст письма',
            'recipient_type_required' => 'Выберите получателя',
            'email_address_required' => 'Укажите email-адрес',
        ],
        'errors' => [
            'recipient_not_resolved' => 'Не удалось определить email получателя',
            'invalid_email' => 'Некорректный email: :email',
            'send_failed' => 'Не удалось отправить письмо: :error',
        ],
    ],
    'send_notification' => [
        'name' => 'Отправить уведомление',
        'description' => 'Отправить уведомление в платформе, письмо на email и сообщение в Telegram',
        'fields' => [
            'title' => ['label' => 'Заголовок уведомления'],
            'body' => ['label' => 'Текст сообщения'],
            'type' => ['label' => 'Тип уведомления'],
            'recipient_type' => ['label' => 'Кому отправить'],
            'recipient_user_id' => ['label' => 'Пользователь'],
            'recipient_role' => ['label' => 'Роль'],
            'recipient_field' => [
                'label' => 'Поле',
                'helper' => 'Выберите поле с ID пользователя',
            ],
        ],
        'options' => [
            'info' => 'Информация',
            'success' => 'Успех',
            'warning' => 'Предупреждение',
            'danger' => 'Ошибка',
            'specific_user' => 'Конкретный пользователь',
            'users_with_role' => 'Пользователи с ролью',
        ],
        'configured' => [
            'summary' => '<strong>:title</strong> | Кому: <strong>:recipient</strong>',
        ],
        'validation' => [
            'title_required' => 'Укажите заголовок уведомления',
            'recipient_type_required' => 'Выберите получателя',
        ],
        'errors' => [
            'recipients_not_resolved' => 'Не удалось определить получателей уведомления',
            'send_failed' => 'Не удалось отправить уведомление ни одному получателю',
        ],
    ],
    'control_condition' => [
        'name' => 'Условие',
        'description' => 'Разветвить процесс по условиям',
        'sections' => [
            'conditions' => [
                'label' => 'Условия',
                'description' => 'Опишите условия для проверки',
            ],
            'branches' => [
                'label' => 'Ветки',
                'description' => 'Действия в зависимости от результата условия',
            ],
        ],
        'fields' => [
            'logic' => ['label' => 'Логика условий'],
            'conditions' => [
                'label' => 'Условия',
                'add' => 'Добавить условие',
            ],
            'left' => [
                'label' => 'Что проверяем',
                'placeholder' => 'Выберите поле или значение',
            ],
            'right' => [
                'label' => 'С чем сравниваем',
                'placeholder' => 'Укажите значение для сравнения',
            ],
            'has_true_branch' => [
                'label' => 'Настроить действия ветки TRUE',
                'helper' => 'Действия, которые выполнятся, если условие истинно',
            ],
            'has_false_branch' => [
                'label' => 'Настроить действия ветки FALSE',
                'helper' => 'Действия, которые выполнятся, если условие ложно',
            ],
            'store_result' => [
                'label' => 'Сохранить результат в контекст',
            ],
        ],
        'logic' => [
            'and' => 'И — все условия должны выполниться',
            'or' => 'ИЛИ — достаточно одного условия',
        ],
        'configured' => [
            'if_one' => '<strong>Если</strong> :left :operator',
            'if_many' => '<strong>Если</strong> условий: :count (:logic)',
        ],
        'validation' => [
            'conditions_required' => 'Добавьте хотя бы одно условие',
            'left_required' => 'Условие :number: заполните левое значение',
            'operator_required' => 'Условие :number: выберите оператор',
        ],
        'errors' => [
            'none_defined' => 'Условия не заданы',
            'evaluation_failed' => 'Не удалось проверить условие: :error',
        ],
    ],
    'http_request' => [
        'name' => 'HTTP-запрос',
        'description' => 'Выполнить HTTP-запрос к внешнему API',
        'sections' => [
            'headers' => 'Заголовки',
            'request_body' => 'Тело запроса',
        ],
        'fields' => [
            'method' => ['label' => 'HTTP-метод'],
            'url' => [
                'label' => 'URL',
                'placeholder' => 'https://api.example.com/endpoint',
            ],
            'headers' => [
                'key' => 'Название заголовка',
                'value' => 'Значение',
                'add' => 'Добавить заголовок',
                'helper' => 'Используйте :secret для секретов, например :example',
            ],
            'body_type' => ['label' => 'Тип тела запроса'],
            'body' => ['label' => 'Тело запроса'],
            'form_data' => [
                'label' => 'Данные формы',
                'key' => 'Название поля',
                'value' => 'Значение',
                'add' => 'Добавить поле',
            ],
            'timeout' => [
                'label' => 'Таймаут (сек.)',
                'helper' => 'Максимальное время ожидания ответа',
            ],
            'retry_count' => [
                'label' => 'Количество повторов',
                'helper' => 'Сколько раз повторять запрос при ошибке',
            ],
            'retry_delay' => [
                'label' => 'Пауза между повторами (мс)',
                'helper' => 'Задержка между повторами в миллисекундах',
            ],
            'store_response' => ['label' => 'Сохранить ответ в контекст'],
            'fail_on_error_status' => [
                'label' => 'Считать HTTP-ошибку ошибкой шага',
                'helper' => 'Пометить действие ошибочным, если статус ответа >= 400',
            ],
        ],
        'body_types' => [
            'json' => 'JSON',
            'form' => 'Form URL-Encoded',
            'raw' => 'Текст',
            'multipart' => 'Multipart Form Data',
        ],
        'validation' => [
            'url_required' => 'Укажите URL',
            'method_required' => 'Выберите HTTP-метод',
        ],
        'errors' => [
            'request_failed_status' => 'HTTP-запрос завершился со статусом :status: :body',
            'connection_failed' => 'Ошибка соединения: :error',
            'request_failed' => 'HTTP-запрос завершился ошибкой: :error',
            'invalid_url' => 'Некорректный URL: :url',
            'invalid_scheme' => 'Разрешены только схемы HTTP и HTTPS, получено: :scheme',
            'blocked_domain' => 'Домен заблокирован: :host',
            'domain_not_allowed' => 'Домена нет в списке разрешенных: :host',
            'private_ip' => 'Запросы к приватным или зарезервированным IP-адресам запрещены: :host',
            'unsupported_method' => 'HTTP-метод не поддерживается: :method',
        ],
    ],
    'run_workflow' => [
        'name' => 'Запустить процесс',
        'description' => 'Запустить другой процесс',
        'sections' => [
            'context_passing' => 'Передача данных',
            'execution_options' => 'Параметры запуска',
        ],
        'fields' => [
            'workflow_id' => [
                'label' => 'Какой процесс запустить',
                'helper' => 'Выберите процесс, который нужно запустить из текущего процесса',
            ],
            'pass_context' => [
                'label' => 'Передать текущий контекст',
                'helper' => 'Передать дочернему процессу данные триггера и переменные',
            ],
            'pass_trigger_model' => [
                'label' => 'Передать модель триггера',
                'helper' => 'Дочерний процесс получит доступ к той же модели триггера',
            ],
            'pass_step_outputs' => [
                'label' => 'Передать результаты предыдущих шагов',
                'helper' => 'Результаты уже выполненных шагов будут доступны в дочернем процессе',
            ],
            'pass_variables' => [
                'label' => 'Передать переменные',
                'helper' => 'Пользовательские переменные будут доступны в дочернем процессе',
            ],
            'wait_for_completion' => [
                'label' => 'Дождаться завершения',
                'helper' => 'Приостановить текущий процесс до завершения дочернего процесса',
            ],
            'max_depth' => [
                'label' => 'Максимальная глубина цепочки',
                'helper' => 'Ограничивает количество вложенных запусков, чтобы избежать бесконечного цикла',
            ],
            'fail_on_child_failure' => [
                'label' => 'Считать ошибкой сбой дочернего процесса',
                'helper' => 'Если дочерний процесс завершится с ошибкой, этот шаг тоже будет ошибочным',
            ],
            'store_result' => [
                'label' => 'Сохранить результат в контекст',
            ],
        ],
        'configured' => [
            'run' => 'Запустить <strong>:name</strong>',
        ],
        'validation' => [
            'workflow_required' => 'Выберите процесс для запуска',
        ],
        'errors' => [
            'workflow_not_found' => 'Процесс для запуска не найден',
            'workflow_not_active' => 'Процесс для запуска не активен',
            'max_depth_exceeded' => 'Превышена максимальная глубина цепочки процессов (:depth)',
            'prepare_failed' => 'Не удалось подготовить запуск процесса: :error',
        ],
    ],
    'transform_data' => [
        'name' => 'Преобразовать данные',
        'description' => 'Изменить, сопоставить или подготовить данные предыдущих шагов',
        'fields' => [
            'operation' => ['label' => 'Операция'],
            'context_key' => [
                'label' => 'Сохранить как',
                'placeholder' => 'my_variable',
                'helper_with_key' => 'Будет доступно в следующих шагах как {{var.:key}}',
                'helper_empty' => 'Введите имя переменной для сохранения результата',
            ],
            'value' => [
                'section_with_key' => 'Значение для {{var.:key}}',
                'section' => 'Значение',
                'label' => 'Значение',
                'placeholder' => 'Статическое значение или {{lead.name}}',
            ],
            'source_path' => [
                'label' => 'Путь к источнику',
                'placeholder' => '{{user.email}} или {{step.step_1.body.data}}',
            ],
            'map_source' => [
                'label' => 'Объект-источник',
                'placeholder' => '{{step.http_request.body}}',
                'helper' => 'Объект, из которого нужно сопоставить поля',
            ],
            'field_mappings' => [
                'label' => 'Сопоставление полей',
                'from' => 'Из поля',
                'from_placeholder' => 'original_field_name',
                'to' => 'В поле',
                'to_placeholder' => 'new_field_name',
                'add' => 'Добавить сопоставление',
            ],
            'transform_source' => [
                'label' => 'Исходное значение',
                'placeholder' => '{{lead.name}}',
            ],
            'transform_type' => ['label' => 'Преобразование'],
            'substring_start' => ['label' => 'Начальная позиция'],
            'substring_length' => ['label' => 'Длина'],
            'search' => ['label' => 'Что найти'],
            'replace_with' => ['label' => 'На что заменить'],
            'split_delimiter' => ['label' => 'Разделитель'],
            'date_format' => [
                'label' => 'Формат даты',
                'helper' => 'Формат даты PHP',
            ],
            'number_decimals' => ['label' => 'Количество знаков после запятой'],
            'merge_sources' => [
                'label' => 'Источники для объединения',
                'source_label' => 'Источник',
                'placeholder' => '{{lead.data}} или {{step.step_1.output}}',
                'add' => 'Добавить источник',
            ],
            'filter_source' => [
                'label' => 'Массив-источник',
                'placeholder' => '{{step.http_request.body.items}}',
            ],
            'filter_field' => ['label' => 'Поле для проверки'],
            'filter_value' => ['label' => 'Значение'],
            'expression' => [
                'label' => 'Выражение',
                'placeholder' => 'Например: {{lead.quantity}} * {{lead.price}}',
                'helper' => 'Поддерживается: :plus, :minus, :multiply, :divide и :variables',
            ],
        ],
        'sections' => [
            'extract_configuration' => 'Настройки извлечения',
            'field_mapping' => 'Сопоставление полей',
            'transformation' => 'Преобразование',
            'merge_objects' => 'Объединение объектов',
            'filter_array' => 'Фильтрация массива',
            'compute_value' => 'Вычисление значения',
        ],
        'operations' => [
            'set' => 'Задать значение',
            'extract' => 'Извлечь значение',
            'map' => 'Сопоставить/переименовать поля',
            'transform' => 'Применить преобразование',
            'merge' => 'Объединить объекты',
            'filter' => 'Отфильтровать массив',
            'compute' => 'Вычислить значение',
        ],
        'transformations' => [
            'uppercase' => 'В верхний регистр',
            'lowercase' => 'В нижний регистр',
            'trim' => 'Обрезать пробелы',
            'slug' => 'Преобразовать в slug',
            'title_case' => 'Каждое слово с заглавной',
            'snake_case' => 'snake_case',
            'camel_case' => 'camelCase',
            'substring' => 'Подстрока',
            'replace' => 'Найти и заменить',
            'split' => 'Разбить в массив',
            'json_encode' => 'Кодировать в JSON',
            'json_decode' => 'Декодировать JSON',
            'date_format' => 'Форматировать дату',
            'number_format' => 'Форматировать число',
        ],
        'configured' => [
            'set' => 'Задать <strong>{{var.:key}}</strong> = <code>:value</code>',
            'extract' => 'Извлечь в <strong>{{var.:key}}</strong>',
            'map' => 'Сопоставить поля в <strong>{{var.:key}}</strong>',
            'transform' => 'Преобразовать в <strong>{{var.:key}}</strong>',
            'merge' => 'Объединить в <strong>{{var.:key}}</strong>',
            'filter' => 'Отфильтровать в <strong>{{var.:key}}</strong>',
            'compute' => 'Вычислить <strong>{{var.:key}}</strong>',
            'default' => 'Преобразовать данные',
        ],
        'validation' => [
            'operation_required' => 'Выберите операцию',
            'value_required' => 'Укажите значение',
            'source_path_required' => 'Укажите путь к источнику',
            'source_object_required' => 'Укажите объект-источник',
            'source_value_required' => 'Укажите исходное значение',
            'source_required' => 'Добавьте хотя бы один источник',
            'source_array_required' => 'Укажите массив-источник',
            'expression_required' => 'Укажите выражение',
        ],
        'errors' => [
            'unknown_operation' => 'Неизвестная операция: :operation',
            'transform_failed' => 'Не удалось преобразовать данные: :error',
        ],
    ],
    'assign_record' => [
        'name' => 'Назначить запись',
        'description' => 'Изменить владельца или ответственного записи',
        'sections' => [
            'assignment' => 'Назначение',
        ],
        'fields' => [
            'target' => ['label' => 'Какую запись назначить'],
            'target_context_path' => ['label' => 'Путь в контексте'],
            'assignment_field' => [
                'label' => 'Поле ответственного',
                'helper' => 'Поле, куда записать ответственного, например assigned_to_id, owner_id, user_id',
            ],
            'assignment_mode' => ['label' => 'Кому назначить'],
            'specific_user_id' => [
                'label' => 'ID пользователя',
                'helper' => 'ID пользователя для назначения',
            ],
            'source_field' => [
                'label' => 'Поле-источник',
                'helper' => 'Поле, где хранится ID пользователя. Можно использовать :variable',
            ],
            'context_user_path' => [
                'label' => 'Путь в контексте',
                'helper' => 'Путь к ID пользователя в контексте',
            ],
            'update_assigned_at' => [
                'label' => 'Обновить время назначения',
                'helper' => 'Записать текущее время в assigned_at, если такое поле есть',
            ],
            'store_in_context' => ['label' => 'Сохранить ID ответственного в контекст'],
        ],
        'modes' => [
            'specific' => 'Конкретный ID пользователя',
            'from_field' => 'Из поля триггера',
            'from_context' => 'Из контекста',
            'specific_label' => 'конкретный пользователь',
            'from_field_label' => 'из поля триггера',
            'from_context_label' => 'из контекста',
        ],
        'configured' => [
            'summary' => 'Назначить <strong>:field</strong> через :mode',
        ],
        'validation' => [
            'assignment_field_required' => 'Укажите поле ответственного',
            'specific_user_required' => 'Укажите ID пользователя для назначения',
            'source_field_required' => 'Укажите поле-источник',
            'context_path_required' => 'Укажите путь в контексте',
        ],
        'errors' => [
            'record_not_found' => 'Запись для назначения не найдена',
            'assignee_not_resolved' => 'Не удалось определить ID ответственного',
            'assign_failed' => 'Не удалось назначить запись: :error',
        ],
    ],
    'clone_record' => [
        'name' => 'Скопировать запись',
        'description' => 'Создать копию записи с настройкой полей',
        'sections' => [
            'field_overrides' => [
                'label' => 'Переопределение полей',
                'description' => 'Измените отдельные поля в копии записи',
            ],
            'relationship_cloning' => [
                'label' => 'Копирование связей',
                'description' => 'Настройте, как копировать связанные записи',
            ],
        ],
        'fields' => [
            'source' => ['label' => 'Исходная запись'],
            'source_context_path' => [
                'label' => 'Путь в контексте',
                'helper' => 'Путь к записи в контексте',
            ],
            'value' => [
                'label' => 'Новое значение',
                'placeholder' => '{{lead.id}} или статическое значение',
            ],
            'field_overrides' => ['add' => 'Добавить переопределение'],
            'clone_relations' => [
                'label' => 'Какие связи копировать',
                'placeholder' => 'items, attachments',
                'helper' => 'Список связей через запятую, которые нужно включить в копию',
            ],
            'clone_mode' => ['label' => 'Режим копирования'],
            'store_in_context' => ['label' => 'Сохранить копию в контекст'],
        ],
        'modes' => [
            'deep' => 'Глубокое копирование (создать новые связанные записи)',
            'shallow' => 'Поверхностное копирование (связать с существующими)',
            'pivot' => 'Только pivot-связи',
        ],
        'configured' => [
            'summary' => 'Скопировать <strong>:source</strong>',
        ],
        'validation' => [
            'context_path_required' => 'Укажите путь в контексте при копировании из контекста',
        ],
        'errors' => [
            'source_not_found' => 'Исходная запись не найдена',
            'clone_failed' => 'Не удалось скопировать запись: :error',
        ],
    ],
    'create_record' => [
        'name' => 'Создать запись',
        'description' => 'Создать новую запись выбранного типа',
        'fields' => [
            'target_model' => [
                'helper' => 'Выберите тип создаваемой записи',
            ],
            'value_type' => ['label' => 'Тип значения'],
            'value_text' => ['static_placeholder' => 'Введите статическое значение'],
            'field_mappings' => ['add' => 'Добавить поле'],
            'inherit_tenant' => [
                'label' => 'Наследовать аккаунт из триггера',
                'helper' => 'Автоматически заполнить tenant_id из записи триггера, если включена мультиаккаунтность',
            ],
            'store_in_context' => ['label' => 'Сохранить созданную запись в контекст'],
        ],
        'configured' => [
            'summary' => 'Создать запись <strong>:model</strong>',
        ],
        'validation' => [
            'target_model_required' => 'Выберите тип записи',
            'field_mapping_required' => 'Добавьте хотя бы одно поле',
        ],
        'errors' => [
            'invalid_target_model' => 'Некорректный класс модели',
            'target_model_not_allowed' => 'Этот тип записи не разрешен для процессов',
            'create_failed' => 'Не удалось создать запись: :error',
        ],
    ],
    'delete_record' => [
        'name' => 'Удалить запись',
        'description' => 'Удалить запись мягко или окончательно',
        'sections' => [
            'delete_options' => 'Параметры удаления',
            'safety' => 'Безопасность',
        ],
        'fields' => [
            'target' => ['label' => 'Какую запись удалить'],
            'context_path' => [
                'label' => 'Путь в контексте',
                'helper' => 'Путь к записи в контексте, например created_record или step_id.output',
            ],
            'delete_type' => [
                'label' => 'Тип удаления',
                'helper' => 'Окончательное удаление нельзя отменить',
            ],
            'cascade_relations' => [
                'label' => 'Удалить связанные записи',
                'helper' => 'Также удалить записи в указанных связях',
            ],
            'cascade_relationships' => [
                'label' => 'Связи для каскадного удаления',
                'placeholder' => 'notes, attachments',
                'helper' => 'Список связей через запятую, которые тоже нужно удалить',
            ],
            'fail_if_not_found' => [
                'label' => 'Считать ошибкой, если запись не найдена',
                'helper' => 'Остановить процесс, если запись для удаления не существует',
            ],
        ],
        'types' => [
            'soft' => 'Мягкое удаление (в корзину)',
            'hard' => 'Окончательное удаление',
            'soft_label' => 'мягко удалить',
            'hard_label' => 'окончательно удалить',
        ],
        'configured' => [
            'summary' => '<strong>:type</strong> :target',
        ],
        'validation' => [
            'context_path_required' => 'Укажите путь в контексте при удалении из контекста',
        ],
        'errors' => [
            'record_not_found' => 'Запись для удаления не найдена',
            'record_not_found_reason' => 'Запись не найдена',
            'delete_failed' => 'Не удалось удалить запись: :error',
        ],
    ],
    'update_records' => [
        'name' => 'Обновить записи',
        'description' => 'Обновить одну или несколько записей новыми значениями',
        'sections' => [
            'safety_output' => 'Безопасность и результат',
        ],
        'fields' => [
            'mode' => [
                'label' => 'Режим',
                'helper' => 'Простой режим обновляет запись триггера. Расширенный режим позволяет искать любые записи.',
            ],
            'target_model' => ['label' => 'Модель'],
            'value_type' => ['label' => 'Тип значения'],
            'value_text' => ['static_placeholder' => 'Введите статическое значение'],
            'filters' => [
                'add' => 'Добавить фильтр',
                'in_helper' => 'Для оператора "в списке" используйте значения через запятую',
            ],
            'updates' => ['add' => 'Добавить поле'],
            'use_timestamps' => [
                'label' => 'Обновить временные метки',
                'helper' => 'Обновить поле updated_at',
            ],
            'max_records' => [
                'label' => 'Максимум записей',
                'helper' => 'Ограничение количества обновляемых записей',
            ],
            'fail_on_limit' => [
                'label' => 'Считать ошибкой превышение лимита',
                'helper' => 'Остановить процесс, если найдено больше записей, чем лимит',
            ],
            'store_count' => ['label' => 'Сохранить количество обновленных записей в контекст'],
        ],
        'modes' => [
            'simple' => 'Простой (обновить запись триггера)',
            'advanced' => 'Расширенный (найти записи)',
        ],
        'configured' => [
            'simple_one' => 'Обновить <strong>:field</strong> у записи триггера',
            'simple_many' => 'Обновить <strong>:count полей</strong> у записи триггера',
            'advanced' => 'Обновить <strong>:model</strong> (:updates полей, :filters фильтров)',
        ],
        'validation' => [
            'updates_required' => 'Добавьте хотя бы одно обновляемое поле',
            'target_model_required' => 'Выберите модель для расширенного режима',
            'protected_field' => 'Нельзя обновлять защищенное поле: :field',
        ],
        'errors' => [
            'no_trigger_model' => 'Нет записи триггера для обновления',
            'no_fields' => 'Не указаны поля для обновления',
            'field_update_failed' => 'Не удалось обновить поля: :error',
            'invalid_target_model' => 'Некорректный класс модели',
            'target_model_not_allowed' => 'Этот тип записи не разрешен для процессов',
            'limit_exceeded' => 'Будет обновлено :count записей, это больше лимита :limit',
            'no_valid_updates' => 'Нет корректных обновлений для применения',
            'update_failed' => 'Не удалось обновить записи: :error',
        ],
    ],
    'run_action' => [
        'name' => 'Запустить действие Laravel',
        'description' => 'Выполнить зарегистрированный класс действия с параметрами',
        'fields' => [
            'action_class' => [
                'label' => 'Действие',
                'helper' => 'Выберите действие для выполнения',
            ],
            'parameter_mapping' => [
                'label' => 'Параметры',
                'key' => 'Параметр',
                'value' => 'Значение / переменная',
                'add' => 'Добавить параметр',
                'helper' => 'Сопоставьте параметры действия со значениями или переменными, например :variable',
            ],
            'context_key' => [
                'helper' => 'Сохранить возвращаемое значение в :variable',
            ],
            'fail_on_exception' => [
                'label' => 'Считать исключение ошибкой',
                'helper' => 'Пометить действие ошибочным, если оно выбросит исключение',
            ],
        ],
        'configured' => [
            'summary' => 'Запустить <strong>:action</strong>',
        ],
        'validation' => [
            'action_class_required' => 'Выберите класс действия',
            'action_class_missing' => 'Выбранный класс действия не существует',
            'execute_missing' => 'У класса действия должен быть метод execute()',
        ],
        'errors' => [
            'invalid_action_class' => 'Некорректный класс действия',
            'action_failed' => 'Действие завершилось с ошибкой: :error',
        ],
    ],
    'evaluate_decision_table' => [
        'name' => 'Проверить таблицу решений',
        'description' => 'Выполнить таблицу решений на данных процесса',
        'fields' => [
            'decision_table_slug' => [
                'label' => 'Таблица решений',
                'helper' => 'Выберите таблицу решений для проверки',
            ],
            'input_mapping' => [
                'label' => 'Входные данные',
                'key' => 'Название поля',
                'value' => 'Значение / переменная',
                'add' => 'Добавить входное поле',
                'helper' => 'Сопоставьте входные поля таблицы со значениями или переменными, например :variable',
            ],
            'context_key' => [
                'helper' => 'Результат будет доступен как :variable',
            ],
            'fail_on_no_match' => [
                'label' => 'Считать ошибкой отсутствие совпадений',
                'helper' => 'Пометить действие ошибочным, если ни одна строка таблицы не подошла',
            ],
        ],
        'configured' => [
            'summary' => 'Проверить <strong>:slug</strong>',
        ],
        'validation' => [
            'decision_table_required' => 'Выберите таблицу решений',
            'input_mapping_required' => 'Добавьте хотя бы одно входное поле',
        ],
        'errors' => [
            'slug_required' => 'Укажите slug таблицы решений',
            'no_matching_rule' => 'Подходящее правило не найдено',
            'no_matching_decision_table_rule' => 'В таблице решений не найдено подходящее правило',
            'evaluation_failed' => 'Не удалось проверить таблицу решений: :error',
        ],
    ],
    'transition_state' => [
        'name' => 'Перевести состояние',
        'description' => 'Перевести модель в целевое состояние Spatie',
        'fields' => [
            'source' => [
                'label' => 'Источник модели',
                'helper' => 'Откуда взять модель для смены состояния',
            ],
            'target_model' => [
                'helper' => 'У какой модели менять состояние',
            ],
            'record_id' => [
                'helper' => 'ID записи для поиска, например :variable',
            ],
            'state_field' => [
                'helper' => 'Какое поле состояния изменить',
            ],
            'target_state' => [
                'label' => 'Целевое состояние',
                'helper' => 'Состояние, в которое нужно перевести',
            ],
            'fail_if_cannot_transition' => [
                'label' => 'Считать ошибкой невозможный переход',
                'helper' => 'Пометить действие ошибочным, если переход запрещен',
            ],
        ],
        'sources' => [
            'trigger' => 'Использовать модель триггера',
            'query' => 'Найти по ID',
        ],
        'configured' => [
            'with_field' => 'Перевести <strong>:field</strong> в <strong>:state</strong>',
            'without_field' => 'Перевести в <strong>:state</strong>',
        ],
        'validation' => [
            'state_field_required' => 'Укажите поле состояния',
            'target_state_required' => 'Укажите целевое состояние',
            'model_type_required' => 'Выберите тип модели при поиске по ID',
            'record_id_required' => 'Укажите ID записи при поиске по ID',
        ],
        'errors' => [
            'state_and_target_required' => 'Укажите поле состояния и целевое состояние',
            'model_not_resolved' => 'Не удалось определить модель',
            'missing_has_states' => 'Модель не использует Spatie HasStates',
            'invalid_state_field' => 'Поле ":field" не является корректным полем состояния Spatie',
            'unknown_state' => 'Неизвестное состояние: :state',
            'transition_not_allowed' => 'Переход запрещен',
            'cannot_transition' => 'Нельзя перевести :field из :from в :to',
            'transition_failed' => 'Не удалось перевести состояние: :error',
        ],
    ],
]);
