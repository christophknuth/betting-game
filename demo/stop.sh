#!/bin/sh
# Stops and removes the read-only demo containers.
ENGINE="$(command -v podman || command -v docker)"

"$ENGINE" container rm --force betting-demo-api >/dev/null 2>&1 || true
"$ENGINE" container rm --force betting-demo-db >/dev/null 2>&1 || true
"$ENGINE" network rm betting-demo >/dev/null 2>&1 || true

echo "Demo stopped."
