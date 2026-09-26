# Deployment Guide — Tittawin Management System

Production deployment checklist, environment configuration, backup/restore, and operational procedures.

## Prerequisites

- Docker Engine 24+ and Docker Compose v2
- A copy of this repository on the host
- TLS termination in front of the published HTTP port when the app is on the public internet

## Deploy

One Compose file and one env file start the whole system: PostgreSQL 18, Redis 7, MinIO, the API, the Messenger worker, and the frontend. The browser uses a single port. `/` is the UI and `/api` is the API.

```bash
cp .env.example .env
docker compose up -d --build
```

On first start the API waits for PostgreSQL and applies migrations. The app is ready when this returns `ok`:

```bash
curl -sf "http://127.0.0.1:${HTTP_PORT:-8080}/api/health"
```

Stop the stack with `docker compose down`. Data volumes are kept. `docker compose down -v` deletes the database and uploaded files.

Local development (Vite, Mailpit, source mounts) uses the other file and the same `.env`:

```bash
docker compose -f docker-compose.dev.yml up -d --build
```

Do not run both stacks at once. They share the project name and host ports.

## Environment Variables

Set these in `.env`. Compose builds `DATABASE_URL` from the Postgres settings, so you do not maintain a second connection string.

| Variable | Required | Description |
|----------|----------|-------------|
| `APP_SECRET` | Yes | Symfony secret. Use a long random value |
| `APP_URL` | Yes | Public URL of the app, including scheme and port |
| `HTTP_PORT` | Yes | Host port for the UI and API. Default `8080` |
| `POSTGRES_DB` | Yes | Database name |
| `POSTGRES_USER` | Yes | Database user |
| `POSTGRES_PASSWORD` | Yes | Database password. URL-safe characters only. Applied when the data volume is first created |
| `MINIO_ACCESS_KEY` | Yes | MinIO root user and app access key |
| `MINIO_SECRET_KEY` | Yes | MinIO root password and app secret |
| `MINIO_BUCKET` | Yes | Document bucket. Created on first PDF upload |
| `MINIO_ENDPOINT` | No | Default `http://minio:9000`. Set this to external S3 if you are not using the bundled MinIO |
| `MESSENGER_TRANSPORT_DSN` | No | Default `redis://redis:6379/messages` |
| `CORS_ALLOW_ORIGIN` | No | Origin regex. Required when `PUBLIC_API_URL` is a different host than the UI |
| `MAILER_DSN` | No | `null://null` discards mail. Set an SMTP DSN for real delivery |
| `PUBLIC_API_URL` | No | Leave empty so the UI calls `/api` on the same origin. Set only for a split API host |

`APP_ENV` is `prod` inside this Compose file. Postgres and the MinIO console bind to `127.0.0.1` unless you change `POSTGRES_BIND` or `MINIO_BIND`. Redis stays on the Compose network only.

Operational defaults do not need seeding: the first time they are needed, a company gets the default production workflow (7 stages, loss reasons — editable under Production → Workflow) and a `MAIN` stock location.

Demo seed data is not loaded automatically. To load it on a fresh database:

```bash
docker compose exec api php bin/console app:seed-identity
docker compose exec api php bin/console app:seed-catalog
docker compose exec api php bin/console app:seed-inventory
docker compose exec api php bin/console app:seed-production-config
```

## Coolify / PaaS Deployment

1. Deploy **PostgreSQL 18**, **Redis 7**, and **MinIO** (or managed S3) as separate services.
2. Deploy the **API** container from `backend/Dockerfile` target `prod` behind nginx.
3. Deploy the **worker** with command:  
   `php bin/console messenger:consume async failed -vv --time-limit=3600`
4. Deploy the **frontend** static build (`npm run build` → serve `dist/`).
5. Configure health checks:
   - Liveness: `GET /api/health`
   - Readiness: `GET /api/ready`
6. Run migrations on deploy: `php bin/console doctrine:migrations:migrate --no-interaction`

## Backup & Restore

### PostgreSQL

**Backup (daily recommended):**

```bash
pg_dump -Fc -h postgres -U tittawin tittawin > backup_$(date +%Y%m%d).dump
```

**Restore:**

```bash
pg_restore -c -h postgres -U tittawin -d tittawin backup_YYYYMMDD.dump
```

Stop the API and worker before restore to avoid concurrent writes.

### MinIO / S3 Documents

**Backup:**

```bash
mc mirror minio/tittawin /backups/minio/tittawin
```

**Restore:**

```bash
mc mirror /backups/minio/tittawin minio/tittawin
```

### Redis

Redis holds ephemeral Messenger queue data. Failed messages are persisted in PostgreSQL (`failed` transport). No Redis backup is required for business data.

## Queue Failure Handling

Messenger configuration (`config/packages/messenger.yaml`):

- **async** transport: 3 retries with exponential backoff (multiplier 2)
- **failed** transport: failed messages stored in PostgreSQL for manual replay

**Inspect failed messages:**

```bash
php bin/console messenger:failed:show
php bin/console messenger:failed:retry
```

**Worker notes:**

- Run at least one worker process per environment
- Use `--time-limit=3600` to recycle workers and prevent memory leaks. The hourly
  `Worker stopped due to time limit of 3600s exceeded` log line is expected; the
  container restart policy starts a fresh worker.
- Do not reuse the API healthcheck (it probes php-fpm on :9000). The worker writes
  `/tmp/messenger-worker.heartbeat` every ~10s; check that it is under 120s old:
  `php -r "exit(@filemtime('/tmp/messenger-worker.heartbeat') > time() - 120 ? 0 : 1);"`
- Monitor worker logs for `GenerateDocumentPdf` failures (PDF generation)

## Observability

- **Structured JSON logs** (production): Monolog writes to stderr with JSON formatter
- **Request logging**: `request.started` / `request.completed` events include correlation ID, path, status, duration
- **Correlation ID**: Pass `X-Correlation-ID` header; echoed on all responses
- **Health endpoints**:
  - `GET /api/health` — liveness
  - `GET /api/ready` — database + cache readiness

## Production Checklist

- [ ] `APP_SECRET`, `POSTGRES_PASSWORD`, and `MINIO_SECRET_KEY` rotated from the example values
- [ ] `APP_URL` matches the public URL
- [ ] TLS enabled in front of `HTTP_PORT`
- [ ] `CORS_ALLOW_ORIGIN` restricted when the API is on another origin
- [ ] MinIO credentials secured
- [ ] API container healthy (`/api/health`), which means migrations have been applied
- [ ] Worker container running (`docker compose ps`)
- [ ] Daily PostgreSQL backups scheduled
- [ ] MinIO/S3 document backup scheduled
- [ ] Health/readiness probes configured in orchestrator
- [ ] Demo seed passwords changed or seed skipped in production

## Demo Credentials (development only)

| Context | URL | Email | Password |
|---------|-----|-------|----------|
| Admin | `/admin/login` | `admin@tittawin.local` | `ChangeMe123!` |
| Portal | `/portal/login` | `customer@tittawin.local` | `ChangeMe123!` |
| Operator | `/admin/login` | `operator@tittawin.local` | `ChangeMe123!` |

**Never use these credentials in production.**
