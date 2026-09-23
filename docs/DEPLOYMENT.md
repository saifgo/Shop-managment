# Deployment Guide — Tittawin Management System

Production deployment checklist, environment configuration, backup/restore, and operational procedures.

## Prerequisites

- Docker Engine 24+ or a PaaS with PostgreSQL 18, Redis 7, and S3-compatible storage
- TLS termination (reverse proxy or load balancer)
- Secrets manager for `APP_SECRET`, database credentials, MinIO keys, JWT signing secret

## Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `APP_ENV` | Yes | Set to `prod` in production |
| `APP_SECRET` | Yes | Symfony secret; rotate periodically |
| `DATABASE_URL` | Yes | PostgreSQL connection string |
| `MESSENGER_TRANSPORT_DSN` | Yes | Redis transport for async jobs |
| `CORS_ALLOW_ORIGIN` | Yes | Allowed frontend origin regex |
| `MINIO_ENDPOINT` | Recommended | S3-compatible endpoint (MinIO or AWS S3) |
| `MINIO_ACCESS_KEY` | Recommended | Storage access key |
| `MINIO_SECRET_KEY` | Recommended | Storage secret key |
| `MINIO_BUCKET` | Recommended | Document PDF bucket name |
| `MAILER_DSN` | Optional | SMTP for transactional mail |

When MinIO variables are omitted, document PDFs fall back to local filesystem storage (`var/storage`) — suitable for single-node dev only.

## Production Docker Compose

Use the production overlay:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
make migrate
make seed
```

The `docker-compose.prod.yml` file:

- Builds API and frontend with `prod` targets
- Disables bind mounts
- Sets `APP_ENV=prod`
- Runs the Messenger worker with restart policy

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
- Use `--time-limit=3600` to recycle workers and prevent memory leaks
- Monitor worker logs for `GenerateDocumentPdf` failures (PDF generation)

## Observability

- **Structured JSON logs** (production): Monolog writes to stderr with JSON formatter
- **Request logging**: `request.started` / `request.completed` events include correlation ID, path, status, duration
- **Correlation ID**: Pass `X-Correlation-ID` header; echoed on all responses
- **Health endpoints**:
  - `GET /api/health` — liveness
  - `GET /api/ready` — database + cache readiness

## Production Checklist

- [ ] `APP_SECRET` and database credentials rotated from defaults
- [ ] TLS enabled on all public endpoints
- [ ] CORS restricted to production frontend origin
- [ ] MinIO bucket created and credentials secured
- [ ] Migrations applied
- [ ] Worker process running with restart policy
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
