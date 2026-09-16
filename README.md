# Clever Web

## Overview
This repository hosts the **Clever Web** Laravel application and its local infrastructure. The production application lives under `application/`, while `docker/` contains the container images used by the Docker Compose stack defined at the repo root.

## Quick start (Docker)
1. Copy environment configuration:
   ```bash
   cp application/.env.example application/.env
   ```
2. Build and start containers:
   ```bash
   docker compose up --build
   ```
3. PHP-FPM is bound to `127.0.0.1:9000`; it requires a host nginx FastCGI configuration. PostgreSQL is mapped to `localhost:5425`.

The host nginx terminates HTTPS, serves static files, and sends FastCGI requests
directly to PHP-FPM on `127.0.0.1:9000`; there is no nginx container. The existing
`app.clevercrm.pro` server block includes
`/etc/nginx/snippets/clever-web-app.conf`. Render it from
`ops/nginx/clever-web-app.conf.template` using `ops/nginx/configure-host.py`.
Only Laravel's `public/index.php` can execute. `/storage/` uses an explicit host
alias because Laravel's storage symlink points inside the PHP container.
Request bodies are limited to 200 MB. The host nginx worker must be able to read
`application/public` and `application/storage/app/public`.

HTTPS temporarily uses TLS 1.2 only as a Beget connectivity workaround applied on
2026-09-13. On the production nginx 1.18 build, the restriction must also be set
in the shared default HTTPS server: `ops/nginx/reject-unconfigured-hosts.conf`,
installed at `/etc/nginx/conf.d/00-reject-unconfigured-hosts.conf`. It affects all
HTTPS hosts on the shared listener; the app template also records the restriction.
The deployment helper renders only the app include, so provision the default-server
file separately. To undo the workaround, remove these two explicit TLS restrictions,
validate and reload nginx, then verify actual TLS 1.2 and TLS 1.3 negotiation.

PHP-FPM starts up to eight workers on demand, releases idle workers after 10
seconds, and recycles them after 500 requests. HTTP requests use a 512 MB PHP
memory limit to retain headroom for Excel previews; CLI, Horizon and scheduler
limits are unchanged. Tune the pool only after measuring memory under load.
FPM and supervisor use the same `www-data` UID/GID 33 so both can write Laravel's
shared storage and cache directories.
Container logs for `app` rotate at 10 MB with three files retained; HTTP access
and error logs use the existing host nginx log rotation.

The production host requires `PHP_BUILD_NETWORK=host` in the repository-root
`.env` because DNS inside its default Docker build network times out. This
setting affects image build steps only; application runtime networks remain
unchanged. Other hosts use the default build network when the variable is unset.
The same root `.env` can select an official Alpine mirror with
`APK_REPOSITORY_MIRROR=https://mirror.yandex.ru/mirrors/alpine` when the default
CDN stalls. The default remains `https://dl-cdn.alpinelinux.org/alpine`.
APK network operations time out after 30 seconds without progress, including
package installation performed by the PHP extension helpers.

After a runtime change, rebuild `app` and refresh the managed host include:
```bash
docker compose up -d --build app
docker compose exec -T app php-fpm -t
sudo python3 ops/nginx/configure-host.py --project-path "$PWD"
curl --resolve app.clevercrm.pro:443:127.0.0.1 -fsS https://app.clevercrm.pro/up
```
The production host must have its app server include provisioned first.
GitHub Actions retains the helper during a code rollback and restores proxying
to port 8080 for revisions predating native FPM.

Prometheus and Blackbox probe `https://app.clevercrm.pro` through the Docker host
gateway, with certificate verification. After changing monitoring Compose
settings, recreate only those two services and restart Prometheus to regenerate
its target template. GitHub Actions refreshes them only if already running.

## Front-end tooling
Vite + Tailwind is used for assets. Use the scripts defined in `application/package.json`:

```bash
cd application
npm install
npm run dev
```


## Repository layout
See the detailed structure in [`docs/STRUCTURE.md`](docs/STRUCTURE.md), system-level documentation in [`docs/CODEBASE.md`](docs/CODEBASE.md), and the route catalog in [`docs/ROUTES.md`](docs/ROUTES.md).

## Monitoring

Monitoring stack and setup instructions are documented in [`docs/MONITORING.md`](docs/MONITORING.md).
