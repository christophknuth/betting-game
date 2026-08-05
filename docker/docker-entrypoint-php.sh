#!/bin/sh
#
# The development counterpart of docker-entrypoint.sh: brings the database up to
# the schema the working tree expects, then starts php-fpm.
#
# Same reasoning as in production - once per container start, before the workers
# exist, and the migrator holds a database lock so two starts cannot collide.
# What differs is how forgiving it has to be: this container is bind-mounted
# from the repository, and the repository has no vendor/.
set -eu

APP=/var/www/html

if [ "${MIGRATE_ON_START:-1}" != "1" ]; then
    echo "entrypoint: MIGRATE_ON_START=0, leaving the schema alone"
elif [ ! -f "$APP/vendor/autoload.php" ]; then
    # Not a failure. vendor/ is gitignored and installed *inside* this
    # container, so on a fresh clone it cannot exist before the first start -
    # refusing to start would leave no container to install it in.
    echo "entrypoint: no vendor/ yet, skipping migrations" >&2
    echo "entrypoint: run 'composer install' in this container, then restart it" >&2
else
    # Waiting, because dev has no health condition on the database: the php
    # container starts the moment MariaDB's container does, and on a fresh
    # volume that one is still reading schema.sql.
    php "$APP/bin/migrate" --wait=60
fi

exec "$@"
