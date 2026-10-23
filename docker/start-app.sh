#!/bin/sh
set -e

php artisan migrate --force --isolated
php artisan clickhouse:migrate

exec php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000 \
  --workers="${OCTANE_WORKERS:-4}" --max-requests="${OCTANE_MAX_REQUESTS:-500}"
