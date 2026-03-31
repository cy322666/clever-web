# Assistant

## Что добавлено

- Новый модуль `assistant` внутри Laravel-платформы.
- Tenant-scoped настройки модуля в `assistant_settings`.
- AI traces / tool logs в `assistant_logs`.
- Безопасные API endpoint-ы для n8n с auth через `X-Assistant-Token`.

## Сущности

- `App\Models\Integrations\Assistant\Setting`
- `App\Models\Integrations\Assistant\AssistantLog`

## Auth для n8n

- Все Assistant endpoint-ы находятся под `/api/assistant/{user_uuid}/...`
- Требуется активный модуль `assistant`
- Требуется заголовок `X-Assistant-Token: <service_token>`
- `user_uuid` и token всегда должны относиться к одному tenant

## Endpoint-ы

- `GET /api/assistant/{user_uuid}/department-summary`
- `GET /api/assistant/{user_uuid}/manager-summary?manager_id={amocrm_staff_id}`
- `GET /api/assistant/{user_uuid}/risky-deals`
- `GET /api/assistant/{user_uuid}/deal-context/{deal_id}`
- `GET /api/assistant/{user_uuid}/unprocessed-leads`
- `GET /api/assistant/{user_uuid}/overdue-tasks`
- `GET /api/assistant/{user_uuid}/deals-without-next-task`
- `GET /api/assistant/{user_uuid}/conversion-delta?days=7`
- `GET /api/assistant/{user_uuid}/daily-summary`
- `GET /api/assistant/{user_uuid}/weekly-summary`
- `POST /api/assistant/{user_uuid}/logs`

## Контракт ответов

- Все analytics endpoint-ы возвращают:
    - `data` с нормализованным payload
    - `meta.generated_at`
    - `meta.user_id`
    - `meta.account_id`
    - `meta.contract = assistant.v1`

## Как это работает

- Laravel остается source of truth и считает факты/сигналы/агрегаты
- n8n использует endpoint-ы как tools
- n8n хранит conversation state, chat history и prompt reconstruction
- LLM получает только подготовленные структуры, а не raw amoCRM JSON
- При необходимости n8n сохраняет traces обратно в Laravel

## Где расширять дальше

- `app/Services/Assistant/AssistantAnalyticsService.php` для новых payload-ов
- `app/Services/Assistant/AssistantAmoApiService.php` для новых выборок из amoCRM
- `app/Http/Controllers/Api/Assistant*Controller.php` для новых API контрактов
- `app/Filament/Resources/Integrations/AssistantResource.php` для новых настроек модуля
