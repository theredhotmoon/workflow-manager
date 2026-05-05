# Workflow Manager

A Laravel 9 + Vue 3 shift management application.

---

## Default Credentials

| Role  | Email                  | Password |
|-------|------------------------|----------|
| Admin | admin@shiftchanger.com | `1234`   |

> **Note:** Change the admin password immediately in any production environment.

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
docker compose run --rm backend php artisan key:generate --show
```

Copy the printed `base64:...` string and paste it as `APP_KEY` in your `.env`.

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

### 4. Build and start the stack

```bash
docker compose up -d --build
```

### 5. Run migrations & seeders

```bash
docker compose exec backend php artisan migrate --seed
```

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

# Run an Artisan command
docker compose exec backend php artisan <command>
```

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
    └── ...
```
