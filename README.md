# Tittawin Management System

Modular monolith ERP for commerce, inventory, production, and finance.

## Stack

| Layer    | Technology                          |
|----------|-------------------------------------|
| Backend  | Symfony 7.4 LTS, PHP 8.3+, DDD      |
| Frontend | React 19, Vite 8, TypeScript      |
| Database | PostgreSQL 18                     |
| Cache    | Redis 7                             |
| Storage  | MinIO (S3-compatible)               |
| Mail     | Mailpit (local capture)             |

## Prerequisites

- Docker Desktop (recommended)
- Node.js 22+ and npm (for local frontend dev)
- PHP 8.3+ and Composer (optional; Docker handles backend)

## Quick Start

```bash
# Copy environment template
cp .env.example .env

# Start the local development stack
make up

# Run database migrations and seed demo data
make migrate
make seed

# Open services
# API:        http://localhost:8080/api/health
# Readiness:  http://localhost:8080/api/ready
# OpenAPI UI: http://localhost:8080/api/doc
# Frontend:   http://localhost:5173
# Mailpit:    http://localhost:8025
# MinIO:      http://localhost:9001
```

## Deploy

The production stack is one Compose file and the project `.env`. It starts PostgreSQL, Redis, MinIO, the API, the queue worker, and the frontend behind a single HTTP port. Migrations run on API startup.

```bash
cp .env.example .env
# Set APP_SECRET, POSTGRES_PASSWORD, and MINIO_SECRET_KEY

docker compose up -d --build
```

Open `http://localhost:8080` (or the host and `HTTP_PORT` from `.env`). See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for backups, mail, and the production checklist.

## Demo Credentials

| Context  | URL                 | Account                               |
|----------|---------------------|---------------------------------------|
| Admin    | `/admin/login`      | `admin@tittawin.local` / `ChangeMe123!` |
| Operator | `/admin/login`      | `operator@tittawin.local` / `ChangeMe123!` |
| Portal   | `/portal/login`     | `customer@tittawin.local` / `ChangeMe123!` |

## Development Commands

```bash
make up          # Start the local development stack
make down        # Stop the local development stack
make deploy      # Build and start the production stack
make deploy-down # Stop the production stack
make logs        # Tail service logs
make migrate     # Run Doctrine migrations
make seed        # Seed identity, catalog, inventory, production config
make test        # Run backend + frontend tests
make lint        # Run all linters
make quality     # Backend quality checks (CS, PHPStan, tests)
make build       # Build Docker images + frontend production bundle
```

### Backend only

```bash
cd backend
composer install
composer test
composer phpstan
composer cs-check
```

### Frontend only

```bash
cd frontend
npm install
npm run dev
npm run test
npm run lint
npm run typecheck
npm run build
npm run test:e2e    # Playwright (requires frontend dev server or preview)
```

## Architecture Overview

```
├── backend/          Symfony 7.4 API (DDD: Domain, Application, Infrastructure, UI)
│   └── src/
│       ├── Domain/           Business rules, state machines, value objects
│       ├── Application/      Use cases, services, projections
│       ├── Infrastructure/   Persistence, storage, security, messaging
│       └── UI/Http/          REST controllers
├── frontend/         React SPA with /portal/* and /admin/* route trees
├── docker-compose.yml       # Production stack (single file + .env)
├── docker-compose.dev.yml   # Local Vite / Mailpit stack
├── docs/DEPLOYMENT.md
└── .github/workflows/ci.yml
```

**Key flows:** Catalog → Cart/Order → Stock reservation & backorders → Production → Delivery → Invoice/Payment → Returns/Exchanges

**Cross-cutting:** JWT auth, permission-based authorization, immutable audit trail, async PDF generation via Messenger, MinIO document storage.

## API Conventions

- Base path: `/api`
- Liveness: `GET /api/health`
- Readiness: `GET /api/ready`
- Dashboards: `GET /api/dashboard/admin`, `GET /api/dashboard/portal`
- Reports: `GET /api/reports/*`
- OpenAPI docs: `/api/doc`
- Standard error format (RFC 7807-inspired JSON)
- `X-Correlation-ID` on all requests/responses
- `Idempotency-Key` on mutating requests

## Phase Status

All eight implementation phases are complete:

| Phase | Scope |
|-------|-------|
| 0 | Foundation — monorepo, Docker, CI, health |
| 1 | Architecture & security — DDD, JWT, permissions |
| 2 | Catalog & customers |
| 3 | Commerce, inventory, backorders |
| 4 | Production |
| 5 | Fulfillment, documents, finance |
| 6 | Returns, purchasing, lightweight finance |
| 7–8 | Dashboards, reports, observability, E2E, deployment docs |

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for production deployment, backup/restore, and operational procedures.
