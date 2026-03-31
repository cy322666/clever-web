# AI-модуль Assistant

## Описание модуля

Assistant — это AI-модуль внутри текущей Laravel-платформы, предназначенный для руководителя и работающий на основе
данных amoCRM.

Его задача не в том, чтобы заменить аналитику или бизнес-логику, а в том, чтобы поверх уже существующих данных и
интеграций дать понятный AI-интерфейс:

- отвечать на вопросы руководителя по CRM;
- собирать ежедневные и недельные сводки;
- подсвечивать риски и просадки;
- давать контекст по сделкам, менеджерам и отделу;
- сохранять backend traces взаимодействий с AI.

Модуль встроен в существующую архитектуру проекта и не является отдельным сервисом. Он использует те же принципы, что и
другие интеграции платформы:

- tenant-scoped настройки на уровне пользователя;
- Laravel как основной backend и source of truth;
- amoCRM как источник оперативных данных;
- n8n как orchestration layer для AI-сценариев;
- LLM только как слой интерпретации и формулировки ответа.

Это означает, что AI не принимает решения на основе сырого CRM JSON и не вычисляет показатели самостоятельно. Сначала
Laravel получает и подготавливает данные, считает агрегаты и сигналы, а уже затем n8n и LLM используют эти данные для
ответа пользователю.

## Что умеет модуль

На текущем этапе Assistant даёт foundation для следующих сценариев:

- хранение настроек AI-модуля для конкретного tenant-а;
- безопасные API endpoint-ы для вызова из n8n;
- логирование AI-взаимодействий и вызовов tools;
- summary-ready analytics payload-ы;
- данные по менеджерам, отделу, рисковым сделкам и динамике за период;
- базу для будущих daily/weekly digest и AI-чата руководителя.

## Граница ответственности

### Что делает Laravel

Laravel остаётся основным слоем для:

- multi-tenant изоляции;
- интеграции с amoCRM;
- получения и нормализации данных;
- расчёта аналитики;
- хранения настроек модуля;
- хранения AI logs и traces;
- выдачи безопасных API endpoint-ов для AI tools.

### Что делает n8n

n8n используется как orchestration layer:

- вызывает Assistant API как tools;
- хранит chat history, thread state и conversation memory;
- восстанавливает prompt context между сообщениями;
- выбирает сценарий обработки;
- обращается к LLM;
- запускает scheduled flows;
- доставляет ответы в Telegram или другой канал;
- при необходимости отправляет traces обратно в Laravel.

### Что делает LLM

LLM:

- формулирует ответ;
- объясняет цифры;
- приоритизирует выводы;
- превращает готовые данные в понятный текст.

LLM не:

- считает KPI;
- определяет риски вместо backend;
- строит аналитику из сырого amoCRM JSON;
- становится источником данных.

## Пользовательский сценарий

Руководитель задаёт вопрос:

`Что у нас просело за неделю?`

Дальше сценарий выглядит так:

1. Вопрос попадает в чат или delivery flow.
2. n8n хранит пользовательское сообщение у себя как часть conversation state.
3. n8n вызывает один или несколько Assistant endpoint-ов, например:
    - `weekly-summary`
    - `conversion-delta`
4. Laravel получает данные из amoCRM текущего аккаунта пользователя.
5. Laravel считает нужные показатели и отдаёт компактный JSON payload.
6. n8n передаёт этот payload в LLM.
7. LLM формирует понятный текстовый ответ.
8. n8n возвращает ответ пользователю.
9. При необходимости traces сохраняются обратно в Laravel.

## Ценность для продукта

Assistant позволяет развивать AI-функциональность без слома текущей архитектуры:

- не переносит ядро системы в n8n;
- не превращает AI в источник бизнес-логики;
- не дублирует аналитику в нескольких местах;
- не ломает tenant isolation;
- использует уже существующие точки расширения платформы.

Именно поэтому модуль выглядит как естественное продолжение текущего проекта, а не как отдельная “AI-платформа внутри
платформы”.

---

## Техническая реализация

## Архитектурная схема

### Основной принцип

- Laravel = source of truth
- amoCRM = источник CRM-данных
- n8n = orchestration layer
- LLM = формулировка ответа

### Внутри Laravel модуль реализован как интеграция

Assistant встроен в тот же паттерн, что и другие интеграции проекта:

- `App`
- `Integrations/*/Setting`
- install command
- Filament resource
- API routes
- middleware
- services

Это соответствует текущему стилю кодовой базы и не создаёт новый архитектурный слой.

## Новые сущности

### 1. assistant_settings

Хранит:

- включён ли модуль;
- service token для вызова из n8n;
- feature flags;
- параметры summary;
- настройки delivery;
- thresholds для risk logic.

Модель:

- `App\Models\Integrations\Assistant\Setting`

### 2. assistant_logs

Хранит:

- endpoint/tool;
- request payload;
- response payload;
- status;
- latency;
- model;
- prompt version;
- token usage;
- ошибки;
- привязку к chat session/message.

Модель:

- `App\Models\Integrations\Assistant\AssistantLog`

## Tenant isolation

Tenant isolation построена вокруг существующего паттерна проекта:

- внешний route содержит `user:uuid`;
- middleware проверяет активность пользователя;
- middleware проверяет активность интеграции `assistant`;
- отдельный middleware проверяет `X-Assistant-Token`;
- все внутренние сущности сохраняются с `user_id`;
- logs дополнительно сохраняются с `account_id`.

Данные всегда берутся от:

- `User`
- `User->account`
- `assistant_settings` этого же пользователя

Это не позволяет запросу переключить tenant через внешний `account_id`.

## API интеграция с n8n

### Авторизация

n8n должен передавать:

- `X-Assistant-Token: <service_token>`

Маршрут:

- `/api/assistant/{user_uuid}/...`

Также проходят обязательные проверки:

- пользователь активен;
- модуль Assistant активен для этого пользователя;
- token совпадает именно с настройкой этого tenant-а.

### Основные endpoint-ы

- `department-summary`
- `manager-summary`
- `risky-deals`
- `deal-context/{deal_id}`
- `unprocessed-leads`
- `overdue-tasks`
- `deals-without-next-task`
- `conversion-delta`
- `daily-summary`
- `weekly-summary`
- `logs`

### Формат ответа

Все analytics endpoint-ы возвращают:

- `data`
- `meta.generated_at`
- `meta.user_id`
- `meta.account_id`
- `meta.contract = assistant.v1`

Это делает контракт предсказуемым для n8n и LLM.

## Где считается аналитика

Аналитика считается в Laravel, в сервисе:

- `App\Services\Assistant\AssistantAnalyticsService`

Он отвечает за:

- department summary;
- manager summary;
- risky deals;
- deal context;
- overdue tasks;
- deals without next task;
- unprocessed leads;
- conversion delta;
- daily/weekly summary payload.

Данные в amoCRM получает отдельный сервис:

- `App\Services\Assistant\AssistantAmoApiService`

Он:

- работает через существующий `App\Services\amoCRM\Client`;
- использует account текущего tenant-а;
- получает сделки и задачи из amoCRM API.

Контроллеры не считают метрики. Они только:

- принимают HTTP запрос;
- валидируют вход;
- вызывают сервис;
- возвращают JSON;
- пишут logs.

## Chat context

Chat context хранится не в Laravel, а в n8n.

n8n отвечает за:

- session/thread state;
- message history;
- prompt reconstruction;
- conversation memory;
- вызов LLM по контексту предыдущих сообщений.

Laravel не хранит переписку и не восстанавливает контекст разговора. Он только:

- отдаёт tool payload-ы;
- хранит настройки;
- хранит optional traces через `assistant_logs`.

## Что именно считает backend, а не LLM

Laravel считает:

- KPI и агрегаты;
- period delta;
- risk flags;
- risk score;
- overdue logic;
- deals without next task;
- unprocessed lead logic;
- manager/department breakdown.

LLM это не считает. Она получает уже готовые структуры и превращает их в текст.

## Точки расширения

Если модуль дальше развивать, основными точками расширения являются:

- `AssistantAnalyticsService` — новые payload-ы и правила;
- `AssistantAmoApiService` — новые выборки из amoCRM;
- Assistant API controllers — новые endpoint-ы;
- `assistant_settings` — новые настройки сценариев;
- Filament resource — UI настроек;
- `assistant_logs` — расширение аудита и трассировки.

## Ограничения текущего foundation

На текущем этапе модуль является хорошим foundation, но не окончательной enterprise-версией.

Что может понадобиться дальше:

- более строгая проверка ownership в endpoint-е логов;
- snapshot/storage слой для больших объёмов аналитики;
- расширенный security layer для service-to-service auth;
- дополнительные тесты на tenant isolation;
- UI просмотра логов и history;
- более тонкая декомпозиция analytics service по мере роста модуля.

## Итог

Assistant реализован как встроенный AI-модуль платформы, а не как отдельная система.

Это решение сохраняет:

- целостность текущей архитектуры;
- единый источник данных;
- tenant isolation;
- контроль над аналитикой в Laravel;
- гибкость orchestration через n8n.

В результате платформа получает рабочую базу для AI-чата руководителя, digest-сценариев и будущего расширения
AI-функциональности без необходимости переписывать систему с нуля.
