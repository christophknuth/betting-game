#!/bin/sh
#
# Turns the container's environment into the things the application cannot get
# any other way at start-up: the SPA's runtime configuration, a writable cache
# directory, and a database whose schema matches this image.
set -eu

SPA_CONFIG=/app/spa/config.js

# --- The SPA's configuration ------------------------------------------------
#
# Vite bakes VITE_* values into the bundle at build time, so a built image
# would otherwise belong to exactly one environment. Writing this file here is
# what lets the same image serve staging and production; the SPA reads it
# before the bundle and falls back to the built-in values when a key is empty
# (see frontend/src/support/runtimeConfig.js).
#
# Written fresh on every start rather than only when missing: an image that
# kept a config from a previous run would point the browser at the wrong
# identity provider, and nothing about the symptom would say so.
cat > "$SPA_CONFIG" <<EOF
// Generated at container start from the environment. Do not edit.
window.__APP_CONFIG__ = {
  keycloakUrl: "${KEYCLOAK_PUBLIC_URL:-}",
  keycloakRealm: "${KEYCLOAK_REALM:-}",
  keycloakClientId: "${KEYCLOAK_FRONTEND_CLIENT_ID:-}"
};
EOF

# --- The cache --------------------------------------------------------------
#
# var/cache holds the compiled DI container and the JWKS cache. The image ships
# it owned by the runtime user, but a deployment that mounts a volume over it
# gets the volume's ownership instead - and then every authenticated route
# fails with a permission error that reads as a 500.
if [ ! -w /app/var/cache ]; then
    echo "entrypoint: /app/var/cache is not writable by $(id -un), refusing to start" >&2
    echo "entrypoint: a volume mounted there must be owned by uid $(id -u)" >&2
    exit 1
fi

# --- The schema -------------------------------------------------------------
#
# `database/schema.sql` is only read into an empty data directory, so from the
# second release onwards nothing brings an existing database up to what the new
# image expects. Doing it here is what makes a deployment one step: the server
# starts against a schema it fits, or it does not start.
#
# Here rather than in a request: this runs once, before the server forks, so
# the four workers that would otherwise start four ALTERs on the same table do
# not exist yet. Two *containers* starting together still can, and the migrator
# takes a lock on the database for exactly that - the second one waits, then
# finds nothing pending.
#
# A failure is fatal on purpose. An application whose schema is older than its
# code answers 500 on the pages that need the new column, and it does so at a
# moment nobody is watching; refusing to start says the same thing while
# somebody still is.
#
# MIGRATE_ON_START=0 for a deployment that would rather run `bin/migrate` as a
# job of its own - the entrypoint then leaves the schema entirely alone.
if [ "${MIGRATE_ON_START:-1}" = "1" ]; then
    php /app/bin/migrate --wait=60
fi

exec "$@"
