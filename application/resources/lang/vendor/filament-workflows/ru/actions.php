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
                'label' => 'Reply-To (необязательно)',
                'helper' => 'Дополнительный email для ответа',
            ],
        ],
        'options' => [
            'specific_email_address' => 'Конкретный email',
            'specific_user' => 'Конкретный пользователь',
            'email' => 'Email',
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
    ],
    'assign_record' => [
        'name' => 'Назначить запись',
        'description' => 'Изменить владельца или ответственного записи',
    ],
    'clone_record' => [
        'name' => 'Скопировать запись',
        'description' => 'Создать копию записи с настройкой полей',
    ],
    'create_record' => [
        'name' => 'Создать запись',
        'description' => 'Создать новую запись выбранного типа',
    ],
    'delete_record' => [
        'name' => 'Удалить запись',
        'description' => 'Удалить запись мягко или окончательно',
    ],
    'update_records' => [
        'name' => 'Обновить записи',
        'description' => 'Обновить одну или несколько записей новыми значениями',
    ],
    'run_action' => [
        'name' => 'Запустить Action',
        'description' => 'Выполнить зарегистрированный Laravel Action с параметрами',
    ],
    'evaluate_decision_table' => [
        'name' => 'Проверить таблицу решений',
        'description' => 'Выполнить decision table на данных процесса',
    ],
    'transition_state' => [
        'name' => 'Перевести состояние',
        'description' => 'Перевести модель в целевое состояние Spatie',
    ],
]);
