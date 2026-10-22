#!/bin/sh
set -e

KEY_FILE=storage/app/app.key

if [ -z "$APP_KEY" ]; then
  if [ ! -s "$KEY_FILE" ]; then
    mkdir -p storage/app
    php -r 'echo "base64:" . base64_encode(random_bytes(32));' > "$KEY_FILE"
  fi
  APP_KEY="$(cat "$KEY_FILE")"
  export APP_KEY
fi

exec "$@"
