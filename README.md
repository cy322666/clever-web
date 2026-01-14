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
3. The PHP application is exposed on `http://localhost:8080`. PostgreSQL is mapped to `localhost:5425`.

## Front-end tooling
Vite + Tailwind is used for assets. Use the scripts defined in `application/package.json`:

```bash
cd application
npm install
npm run dev
```


## Repository layout
See the detailed structure in [`docs/STRUCTURE.md`](docs/STRUCTURE.md), system-level documentation in [`docs/CODEBASE.md`](docs/CODEBASE.md), and the route catalog in [`docs/ROUTES.md`](docs/ROUTES.md).
