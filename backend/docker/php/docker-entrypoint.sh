#!/bin/sh
set -e

if [ "$APP_ENV" != "prod" ]; then
  composer install --no-interaction --prefer-dist --no-scripts || true
fi

php bin/console cache:clear --no-warmup || true
php bin/console cache:warmup || true

exec "$@"
