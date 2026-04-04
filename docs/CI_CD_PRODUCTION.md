# CI/CD: GitHub -> Production с автооткатом

Этот проект теперь поддерживает workflow:

- `.github/workflows/deploy-prod.yml`

Логика:

1. На `push` в `main` запускаются тесты (`php artisan test`) в GitHub Actions.
2. Если тесты зелёные, запускается deploy по SSH на прод-сервер.
3. На сервер выкатывается commit из GitHub (`github.sha`), поднимаются контейнеры.
4. Выполняются:
    - `php artisan optimize:clear`
    - `php artisan migrate --force`
    - `php artisan optimize`
    - `php artisan app:smoke --strict`
    - `curl <PROD_APP_URL>/up`
5. Если любой шаг падает: выполняется rollback к предыдущему commit и перезапуск сервисов.
6. База данных не удаляется и не восстанавливается из дампа автоматически.

## GitHub Secrets (обязательно)

Добавьте в репозитории (`Settings -> Secrets and variables -> Actions`):

- `PROD_SSH_HOST` — хост прод-сервера
- `PROD_SSH_USER` — ssh-пользователь
- `PROD_SSH_PRIVATE_KEY` — приватный ключ для доступа на сервер
- `PROD_PROJECT_PATH` — абсолютный путь к проекту на сервере (где лежит `docker-compose.yml`)
- `PROD_APP_URL` — внешний URL приложения (например `https://app.clevercrm.pro`)

Рекомендуемые:

- `PROD_SSH_PORT` — порт SSH (если не `22`)
- `PROD_SSH_FINGERPRINT` — fingerprint хоста для дополнительной защиты
- `PROD_COMPOSE_FILES` — если нужен нестандартный набор compose-файлов
- `PROD_AUTO_MIGRATE` — `true`/`false` (по умолчанию `true`)
- По умолчанию `PROD_COMPOSE_FILES`: `-f docker-compose.yml -f monitoring/docker-compose.monitoring.prod.yml`

## Требования на сервере

- Установлены `git`, `docker`, `docker compose`, `curl`.
- Репозиторий уже клонирован в `PROD_PROJECT_PATH`.
- Сервер имеет доступ к GitHub (чтобы `git fetch` мог получить target commit).
- Пользователь из `PROD_SSH_USER` может выполнять `docker compose`.

## Важно

- Rollback в этом пайплайне non-destructive: только код и контейнеры, без `DROP/CREATE` базы.
- Если включены миграции (`PROD_AUTO_MIGRATE=true`), используйте backward-compatible миграции (expand/contract), чтобы
  откат кода оставался безопасным.
- Для изменений схемы с удалением колонок/таблиц делайте двухфазный релиз: сначала расширяющая миграция, удаление во
  втором релизе.
