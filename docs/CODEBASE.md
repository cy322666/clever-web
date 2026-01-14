# Codebase documentation

## Technology stack
- **Backend:** Laravel 12 on PHP 8.2, configured via Composer dependencies.
- **Admin/UI:** Filament 4 for admin panels and widgets.
- **Front-end assets:** Vite + Tailwind CSS for asset bundling and styling.
- **Database:** PostgreSQL (Docker Compose).

## Application entry points
- **HTTP:** `public/index.php` bootstraps the Laravel application and handles web traffic.
- **CLI:** `artisan` provides Laravel CLI commands used for migrations, queues, and tasks.

## Main functional areas

### HTTP layer
- **Routes:** `routes/web.php` defines the base web entry points; `routes/api.php` defines webhook-style integration endpoints for external services (Bizon, GetCourse, Tilda, AlfaCRM, amoCRM, etc.).
- **Controllers:** API controllers in `app/Http/Controllers/Api/` handle integration requests; shared controller base in `app/Http/Controllers/Controller.php`.
- **Middleware:** custom middleware lives in `app/Http/Middleware/` and is referenced by the route groups.

### Domain models
- **Core models:** `app/Models` contains entities like `User`, `App`, `Webhook`, and integration-specific models (amoCRM, Clever, Core).
- **Observers:** `app/Observers` contains model observers to hook into lifecycle events.

### Jobs and background processing
Queueable jobs live in `app/Jobs/` and cover integrations like AlfaCRM, GetCourse, Bizon, YClients, and distribution workflows.

### Services and integrations
- **Integration clients:** `app/Services` includes API clients for amoCRM, AlfaCRM, Bizon365, GetCourse, Telegram, YClients, and supporting classes for document formatting/exports.
- **Helpers/traits:** reusable traits and helper actions reside under `app/Helpers`.

### Admin and UI
- **Filament panels:** `app/Providers/Filament` configures Filament admin panels for the application.
- **Livewire widgets:** `app/Livewire` includes Livewire widgets used by the admin UI.
- **Resources:** frontend assets and language files are located in `resources/` (CSS, JS, Blade views, localization).

### Data layer
- **Migrations:** database schema history lives in `database/migrations/` (accounts, webhooks, integrations, etc.).
- **Factories/seeders:** `database/factories` and `database/seeders` support test and seed data.

## Infrastructure
- **Docker Compose:** services include `app`, `postgresql`, and `supervisor` for queues/schedulers. The app container mounts `application/` and exposes port 8080, while Postgres is mapped to 5425.
- **Container images:** Dockerfiles live under `docker/images/` (php82, postgres config, supervisor configs).
