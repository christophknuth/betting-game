#!/bin/sh
# Starts the read-only demo: MariaDB with seeded data plus a PHP server.
# Stop again with demo/stop.sh. Works with podman or docker.
set -e

PORT="${1:-8080}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENGINE="$(command -v podman || command -v docker)"
NETWORK=betting-demo
DB=betting-demo-db
API=betting-demo-api
IMAGE=betting-demo-php

if [ -z "$ENGINE" ]; then
    echo "Neither podman nor docker found." >&2
    exit 1
fi

echo "==> Preparing network"
"$ENGINE" network create "$NETWORK" >/dev/null 2>&1 || true

echo "==> Removing previous demo containers"
"$ENGINE" container rm --force "$DB" >/dev/null 2>&1 || true
"$ENGINE" container rm --force "$API" >/dev/null 2>&1 || true

echo "==> Starting MariaDB with schema and demo data"
"$ENGINE" run -d --name "$DB" --network "$NETWORK" \
    -e MARIADB_ROOT_PASSWORD=secret \
    -e MARIADB_DATABASE=betting_game \
    -v "$ROOT/database/schema.sql:/docker-entrypoint-initdb.d/01-schema.sql:ro" \
    -v "$ROOT/demo/seed.sql:/docker-entrypoint-initdb.d/02-seed.sql:ro" \
    mariadb:11.3 >/dev/null

printf '    waiting for the database'
ready=0
i=0
while [ "$i" -lt 60 ]; do
    if "$ENGINE" exec "$DB" mariadb -uroot -psecret -e 'SELECT 1' >/dev/null 2>&1; then
        ready=1
        break
    fi
    printf '.'
    sleep 2
    i=$((i + 1))
done
echo ''

if [ "$ready" -ne 1 ]; then
    echo "Database did not become ready. Logs:" >&2
    "$ENGINE" logs --tail 30 "$DB"
    exit 1
fi

rows="$("$ENGINE" exec "$DB" mariadb -uroot -psecret -N -B -e 'SELECT COUNT(*) FROM betting_game.prediction')"
if [ "$rows" -eq 0 ]; then
    echo "Demo data was not loaded." >&2
    exit 1
fi
echo "    demo data loaded ($rows predictions)"

echo "==> Building the PHP image"
"$ENGINE" build -q -t "$IMAGE" -f "$ROOT/demo/Containerfile" "$ROOT" >/dev/null

echo "==> Starting the API"
"$ENGINE" run -d --name "$API" --network "$NETWORK" \
    -e DB_HOST="$DB" \
    -e DB_DATABASE=betting_game \
    -e DB_USERNAME=root \
    -e DB_PASSWORD=secret \
    -p "$PORT:8080" \
    -v "$ROOT:/app" \
    "$IMAGE" >/dev/null

sleep 2

cat <<EOF

Demo is running on http://localhost:$PORT

  Overview          http://localhost:$PORT/
  Seeded data       http://localhost:$PORT/demo-data
  All predictions   http://localhost:$PORT/predictions
  Alice's tips      http://localhost:$PORT/participants/1/predictions
  A result          http://localhost:$PORT/events/41/result

  Stop with: demo/stop.sh
EOF
