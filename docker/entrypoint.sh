#!/bin/sh
set -e

KEY_FILE=/app/storage/app/app.key

if [ -z "$APP_KEY" ]; then
  if [ ! -s "$KEY_FILE" ]; then
    mkdir -p "$(dirname "$KEY_FILE")"
    umask 077
    tmp="$(mktemp "$KEY_FILE.XXXXXX")"
    php -r 'echo "base64:" . base64_encode(random_bytes(32));' > "$tmp"
    # Atomic publish: the hard link fails if the target exists, the first writer wins.
    ln "$tmp" "$KEY_FILE" 2>/dev/null || true
    rm -f "$tmp"
  fi
  APP_KEY="$(cat "$KEY_FILE")"
  export APP_KEY
fi

if [ -z "$APP_KEY" ]; then
  echo "entrypoint: APP_KEY could not be initialised" >&2
  exit 1
fi

exec "$@"
