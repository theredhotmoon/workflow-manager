# Workflow Manager

A Laravel 9 + Vue 3 shift managementand workflow management application.

---

## Default credentials (local development only)

These accounts are created **only when `APP_ENV` is `local` or `testing`** (the hybrid dev stack,
`docker-compose.dev.yml`, the test suites and the e2e baseline seed). Their passwords are public, so
the seeders refuse to create them anywhere else.

| Role                | Email                    | Password      |
|---------------------|--------------------------|---------------|
| Admin               | admin@shiftchanger.com   | `1234`        |
| Employee (demo)     | regular@shiftchanger.com | `password123` |
| Schedule maintainer | schedule@company.com     | `schedule123` |

In production the first admin's password comes from `SEED_ADMIN_PASSWORD` (see
[Run migrations & seeders](#5-run-migrations--seeders)).

> **Existing deployments:** if your server was seeded before this change, `admin@shiftchanger.com`
> may still have the password `1234`. Log in and change it now (**Change password**), or reset it
> from another admin account.

---

## Running with Docker (recommended)

All three services — PostgreSQL, Laravel API, and the Vue 3 SPA — are orchestrated by a single `docker-compose.yml` at the root of this directory.

### Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or Docker Engine + Compose plugin)

### 1. Create the environment file

```bash
cp .env.example .env
```

### 2. Generate an application key

```bash
echo "base64:$(openssl rand -base64 32)"
```

Copy the printed `base64:...` string and paste it as `APP_KEY` in your `.env`.
`docker compose` refuses to start without `APP_KEY` and `DB_PASSWORD`; there are no built-in defaults.

### 3. Fill in the remaining `.env` values

| Variable                    | Description                                      |
|-----------------------------|--------------------------------------------------|
| `APP_KEY`                   | The key you generated above                      |
| `APP_URL`                   | Public URL of the server (e.g. `http://localhost`) |
| `FRONTEND_PORT`             | Host port the app is exposed on (default `80`)   |
| `DB_DATABASE`               | Postgres database name                           |
| `DB_USERNAME`               | Postgres username                                |
| `DB_PASSWORD`               | Postgres password                                |
| `SANCTUM_STATEFUL_DOMAINS`  | Domain/IP of the frontend (e.g. `localhost`)     |
| `API_RATE_LIMIT`            | Max API requests per minute, per user (default `60`) |
| `LOGIN_RATE_LIMIT`          | Failed logins allowed per minute for one email from one IP before `429` (default `5`) |
| `TRUSTED_PROXIES`           | Proxies whose `X-Forwarded-For` is believed (default: private ranges, i.e. the compose network) |
| `SESSION_SECURE_COOKIE`     | `true` to send the session cookie over HTTPS only. Set it in production (see below) |

### HTTPS in production

The stack itself speaks plain HTTP on `FRONTEND_PORT`. On a real server, put a TLS-terminating
reverse proxy (Caddy, Traefik, nginx + certbot, a cloud load balancer) in front of it, serve the app
only over `https://`, and set in `.env`:

```dotenv
APP_URL=https://your-domain
SESSION_SECURE_COOKIE=true
```

so the browser never sends the session cookie over plain HTTP. Leave it `false` only for local
development over `http://localhost`.

### 4. Build and start the stack

```bash
docker compose up -d --build
```

### 5. Run migrations & seeders

The stack runs with `APP_ENV=production`, so the seeder creates the first admin
(`admin@shiftchanger.com`) only with a password you choose, passed as `SEED_ADMIN_PASSWORD`
(at least 12 characters). Without it, it stops with an error and creates nothing; no demo users
are created in production.

```bash
docker compose exec backend php artisan migrate --force
docker compose exec -e SEED_ADMIN_PASSWORD='choose-a-long-password' backend php artisan db:seed --force
```

Re-running the seeder never changes an existing admin's password. To keep the password out of your
shell history, read it into the environment first
(`read -rs SEED_ADMIN_PASSWORD && export SEED_ADMIN_PASSWORD` in bash) and pass `-e SEED_ADMIN_PASSWORD`
without a value.

### Accessing the app

| Service  | URL                                              |
|----------|--------------------------------------------------|
| Frontend | `http://localhost` (or whatever `FRONTEND_PORT` is set to) |
| API      | Proxied automatically through the frontend Nginx — no separate port needed |

### Useful commands

```bash
# View logs for all services
docker compose logs -f

# View logs for a specific service
docker compose logs -f backend

# Stop the stack (preserves volumes)
docker compose down

# Stop and destroy all data (volumes)
docker compose down -v

# Open a shell inside the backend container
docker compose exec backend sh

# Run an Artisan command (as www-data, so any log files it creates stay
# writable by php-fpm — the container also re-chowns storage/ on every start)
docker compose exec -u www-data backend php artisan <command>
```

---

## Running Tests

The backend test suite uses [Pest](https://pestphp.com/) and runs against a dedicated Postgres database (`shift_changer_test`), kept separate from your dev database.

### 1. Create the test database (one-time)

Connect to whichever Postgres instance your `backend/.env` points at (`DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`) and create a second database:

```sql
CREATE DATABASE shift_changer_test;
```

For example, with `psql` against a locally-installed Postgres:

```bash
psql -h $DB_HOST -p $DB_PORT -U $DB_USERNAME -c "CREATE DATABASE shift_changer_test;"
```

If you're running the fully-Dockerized stack instead, note that the `db` service in `docker-compose.yml` doesn't publish its port to the host, so connect to it via the container's own network instead:

```bash
docker compose exec db psql -U postgres -c "CREATE DATABASE shift_changer_test;"
```

### 2. Run the suite

```bash
cd backend
composer test
```

### Frontend tests (Playwright)

The frontend uses Playwright for everything: Node-mode unit tests for composables, and end-to-end
tests that drive real Chromium against the real Dockerized backend (no API mocks).

**Prerequisites**

```bash
# from the repo root, once: a root .env with APP_KEY and DB_PASSWORD set (compose needs both;
# see "Create the environment file" above)
cp .env.example .env

# start the database and backend the e2e suite talks to (127.0.0.1:8090)
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d db backend

cd frontend
npm ci
npx playwright install chromium
```

**Commands** (run from `frontend/`)

```bash
npm run test       # unit tests (composables), no backend needed
npm run test:e2e   # end-to-end tests; starts `npm run dev:hybrid` on :5173 itself
npm run test:all   # unit, then e2e
```

> **Warning: `npm run test:e2e` resets your local development database** (`migrate:fresh` plus the
> e2e baseline seed, run inside the `shift_changer_backend` container). Never run it against data
> you want to keep.

Notes:

- The suite starts its own dev server. A plain `npm run dev` already listening on `:5173` would be
  reused by Playwright and serve the wrong page (no `/api` proxy), so stop it first — or run the
  suite on another port: `E2E_PORT=5183 npm run test:e2e`. That also lets it run next to another
  project's dev server on `:5173`. (The dev backend accepts session cookies from any `localhost`
  port; after changing `docker-compose.dev.yml`, recreate the backend container.)
- New specs must import `test` and `expect` from `e2e/fixtures/auth.js` (not `@playwright/test`).
  That file provides the `adminPage` / `employeePage` fixtures.
- The API rate limit (`API_RATE_LIMIT`, default 60 requests/minute per user) is raised to 1000 by
  `docker-compose.dev.yml`, so the specs, which all share one admin user, are not throttled. A value
  set in your root `.env` takes precedence over that default — leave it unset (as in `.env.example`)
  for local development. Global
  setup still clears the cache (and with it the limiter counters) once per run. If you see 429s,
  rebuild the backend with both compose files (`docker compose -f docker-compose.yml -f
  docker-compose.dev.yml up -d --build backend`) so the dev limit is applied.
- Specs share one database per run, so give every group a unique name and use a month no other spec
  uses. Do not enable Playwright `retries` or `--repeat-each`.

---

## Hybrid dev mode (db + backend in Docker, frontend local)

Runs the database and Laravel API in containers, while the Vue SPA runs on your
host machine via the Vite dev server — no PHP/Composer/Postgres install needed
locally, but you still get instant frontend HMR.

```powershell
./dev.ps1
```

This brings up `db` and `backend` (using `docker-compose.dev.yml`, which exposes
the backend on `http://localhost:8090` — override with `BACKEND_PORT` in `.env`
if that's taken too — and relaxes a few env vars for local use), waits for the
backend to respond, then runs `npm run dev:hybrid` in `frontend/`.

Open **`http://localhost:5173`**. The Vite dev server (`frontend/vite.config.hybrid.js`)
serves the standalone SPA and proxies `/api` and `/sanctum` requests to the
containerized backend, mirroring what the production frontend Nginx container
does — so Sanctum's cookie-based auth works without CORS changes. Set
`BACKEND_URL` to point the proxy somewhere other than `http://localhost:8090`.

> Use `npm run dev:hybrid`, not `npm run dev` — the latter loads
> laravel-vite-plugin, which shows its own placeholder page on `:5173`.
> The backend on `:8090` serves the API only in this mode, so opening it in
> the browser fails with "Vite manifest not found".

To run the same containers manually instead of the script:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build db backend
cd frontend && npm run dev:hybrid
```

Full-Docker mode (all three services) is unaffected — see above.

---

## Running without Docker (local development)

In local dev mode the Laravel backend and the Vite dev server run as separate processes. The `laravel-vite-plugin` wires them together — assets are built directly into `backend/public/build`, and Laravel serves the SPA.

### Prerequisites

- **PHP** ≥ 8.0 with extensions: `pdo_pgsql`, `zip`, `opcache`
- **Composer** ≥ 2
- **Node.js** ≥ 20 and **npm**
- **PostgreSQL** ≥ 14 running locally

---

### Backend

```bash
cd backend

# 1. Install PHP dependencies
composer install

# 2. Create the environment file
cp .env.example .env

# 3. Generate the application key
php artisan key:generate
```

Open `backend/.env` and configure the database connection to point at your local Postgres instance:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=shift_changer
DB_USERNAME=postgres
DB_PASSWORD=your_local_password
```

```bash
# 4. Run migrations and seed the database
php artisan migrate --seed

# 5. Start the development server (listens on http://localhost:8000)
php artisan serve
```

---

### Frontend

Open a **second terminal**:

```bash
cd frontend

# 1. Install Node dependencies
npm install

# 2. Start the Vite dev server
npm run dev
```

Vite will start and Laravel will automatically inject the HMR script. Open **`http://localhost:8000`** in your browser — the Vite dev server is used transparently in the background.

> The `npm run dev` command uses `vite.config.js`, which pipes assets through the Laravel public directory (`../backend/public/build`).  
> The Docker build uses `vite.config.docker.js` instead (`npm run build:docker`), which produces a standalone `dist/` folder served by a dedicated Nginx container — do **not** use this config for local development.

---

## Project structure (quick reference)

```
workflow-manager/
├── docker-compose.yml        # Orchestrates db, backend, frontend
├── .env.example              # Root env template for Docker
├── backend/                  # Laravel 9 API
│   ├── Dockerfile
│   ├── .env.example          # Laravel env template for local dev
│   └── ...
└── frontend/                 # Vue 3 SPA
    ├── Dockerfile
    ├── vite.config.js        # Local dev config (laravel-vite-plugin)
    ├── vite.config.docker.js # Docker build config (standalone dist/)
    ├── vite.config.hybrid.js # Hybrid dev config (docker config + API proxy)
    └── ...
```
