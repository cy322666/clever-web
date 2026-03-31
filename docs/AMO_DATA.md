# amoCRM Data

Модуль `amo-data` добавляет локальный слой operational data из amoCRM для будущей аналитики и AI.

## Что переиспользуется

- `accounts` для amoCRM credentials, subdomain и zone
- `amocrm_statuses` как reference layer для pipeline/status
- `amocrm_staffs` как reference layer для ответственных
- `amocrm_fields` как metadata layer для custom fields
- `App\Services\amoCRM\Client` и `App\Services\amoCRM\Models\Account` для авторизации и sync справочников

## Какие таблицы новые

- `amo_data_settings`
- `amocrm_leads`
- `amocrm_tasks`
- `amocrm_events`
- `amocrm_sync_runs`

## Что синкается

- сделки (`amocrm_leads`)
- задачи (`amocrm_tasks`)
- аналитически значимые события (`amocrm_events`)

События текущего этапа:

- `deal_created`
- `deal_stage_changed`
- `deal_responsible_changed`
- `deal_closed_won`
- `deal_closed_lost`
- `task_created`
- `task_completed`

## Как работает sync

- initial sync:
    - обновляет справочники amoCRM
    - загружает все сделки и задачи
    - создает локальные snapshot-записи
    - строит baseline events по доступному текущему состоянию

- periodic sync:
    - обновляет справочники amoCRM
    - запрашивает сделки и задачи по `updated_at`
    - сравнивает новое состояние с локальным snapshot
    - записывает только фактические изменения и derived events

## Команды

- `php artisan app:amo-data-sync {user_id} --initial`
- `php artisan app:amo-data-sync {user_id}`
- `php artisan app:amo-data-sync-periodic`

`app:amo-data-sync-periodic` добавлен в scheduler и запускается каждые 30 минут. Частота для конкретного tenant-а
дополнительно ограничивается `settings.sync_interval_minutes`.

## Ограничения текущего этапа

- нет вебхуков
- нет звонков
- нет переписок и notes
- нет custom field values в fact layer
- удаления сущностей из amoCRM надежно не отслеживаются
- история изменений строится сравнением snapshot-ов, а не полноценной event feed
