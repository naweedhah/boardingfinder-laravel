#!/bin/sh
# Container start-up for the API and the Reverb worker (same image, different CMD).
set -e

if [ -z "$APP_KEY" ]; then
  # Fine for a local demo; set a fixed APP_KEY in production so tokens survive restarts.
  APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
  export APP_KEY
  echo "APP_KEY not set: generated a temporary one for this container."
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  # The database container may still be starting even after its health check passes once.
  tries=0
  until php artisan migrate --force; do
    tries=$((tries + 1))
    [ "$tries" -ge 10 ] && echo "Database not reachable, giving up." && exit 1
    echo "Waiting for the database... ($tries)"
    sleep 3
  done

  # Seed demo data once per volume.
  if [ "${SEED_DEMO:-false}" = "true" ] && [ ! -f storage/.demo-seeded ]; then
    php artisan db:seed --force && touch storage/.demo-seeded
  fi

  php artisan storage:link >/dev/null 2>&1 || true
fi

exec "$@"
