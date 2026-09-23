#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example"
fi

docker compose -f docker-compose.dev.yml up -d --build
echo "Waiting for API health..."
for i in $(seq 1 30); do
  if curl -sf http://localhost:8080/api/health > /dev/null 2>&1; then
    echo "API is healthy"
    break
  fi
  sleep 2
done

docker compose -f docker-compose.dev.yml exec api php bin/console doctrine:migrations:migrate --no-interaction || true

echo ""
echo "Tittawin Management System is running:"
echo "  API:      http://localhost:8080/api/health"
echo "  OpenAPI:  http://localhost:8080/api/doc"
echo "  Frontend: http://localhost:5173"
echo "  Mailpit:  http://localhost:8025"
echo "  MinIO:    http://localhost:9001"
