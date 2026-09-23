#!/bin/sh
set -e

wait_for_database() {
  attempt=0
  echo "Waiting for PostgreSQL..."
  until php <<'PHP'
<?php
$url = getenv('DATABASE_URL') ?: '';
$parts = parse_url($url);
if ($parts === false || empty($parts['host'])) {
    fwrite(STDERR, "DATABASE_URL is missing or invalid\n");
    exit(1);
}
$db = ltrim($parts['path'] ?? '', '/');
$port = $parts['port'] ?? 5432;
$user = rawurldecode($parts['user'] ?? '');
$pass = rawurldecode($parts['pass'] ?? '');
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $parts['host'], $port, $db);
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_TIMEOUT => 3]);
$pdo->query('SELECT 1');
PHP
  do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
      echo "PostgreSQL did not become ready" >&2
      exit 1
    fi
    sleep 2
  done
}

if [ "$APP_ENV" != "prod" ]; then
  composer install --no-interaction --prefer-dist --no-scripts || true
  php bin/console cache:clear --no-warmup || true
  php bin/console cache:warmup || true
else
  mkdir -p var/cache var/log
  if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    wait_for_database
    echo "Applying database migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
  fi
  php bin/console cache:warmup --no-optional-warmers || true
  chown -R www-data:www-data var
fi

exec "$@"
