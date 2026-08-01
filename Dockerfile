# The production image: SPA and API in one, served by FrankenPHP.
#
# FrankenPHP is Caddy with PHP embedded, so one process serves the built SPA as
# static files and runs the front controller - no php-fpm, no second web
# server, no supervisor holding two of them together in one container.
#
# This is deliberately not the development setup. docker-compose.yml bind-mounts
# the repository so edits are live; here everything is copied in, and the image
# has no Composer, no Node and no sources it does not need at runtime.

# --- 1: the SPA -------------------------------------------------------------
FROM node:24-alpine AS spa

WORKDIR /build

# Copied before the sources so the dependency layer survives a code change.
COPY frontend/package.json frontend/package-lock.json ./
# `npm ci`, not `npm install`: it installs exactly what the lockfile pins, so
# the image cannot quietly get a different dependency tree than CI tested.
RUN npm ci

COPY frontend/ ./
RUN npm run build

# --- 2: the PHP dependencies -------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /build

# Dependencies first and on their own layer, so editing a class does not
# reinstall them. --no-autoloader because the autoloader cannot be built yet:
# src/ is not here, and a classmap generated without it would be empty.
COPY composer.json composer.lock ./
# --no-dev drops PHPUnit, PHPStan and phpcs; they have no business in a
# production image.
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-autoloader

# Now the sources, and only now the autoloader. --classmap-authoritative makes
# it skip the filesystem entirely, which is only safe once every class it will
# ever be asked for is actually present - build it a step earlier and every
# `BettingGame\...` class is simply "not found", with no fallback to PSR-4 to
# save it.
COPY src/ ./src/
RUN composer dump-autoload \
    --no-dev \
    --no-interaction \
    --classmap-authoritative

# --- 3: the runtime ----------------------------------------------------------
FROM dunglas/frankenphp:php8.4-alpine

# pdo_mysql for the database, opcache because this is a production image and
# recompiling every request would be a waste, and apcu because PHP-DI's
# definition cache requires it - Container::build() turns that on together with
# compilation whenever APP_ENV is production, and without the extension the
# whole bootstrap fails with "APCu is not enabled".
# install-php-extensions ships with the FrankenPHP images and resolves the
# build dependencies itself.
RUN install-php-extensions pdo_mysql opcache apcu

WORKDIR /app

# Only what the application needs to run. Notably absent: tests/, frontend/
# sources, docker-compose files and the documentation.
COPY --chown=www-data:www-data src/ ./src/
COPY --chown=www-data:www-data public/ ./public/
COPY --chown=www-data:www-data config/ ./config/
COPY --chown=www-data:www-data --from=vendor /build/vendor/ ./vendor/
COPY --chown=www-data:www-data --from=spa /build/dist/ ./spa/

COPY docker/Caddyfile.production /etc/frankenphp/Caddyfile
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# The compiled DI container and the JWKS cache are written at runtime, so this
# has to belong to the user the server runs as. A deployment that mounts a
# volume here has to match that ownership - the entrypoint checks and refuses
# to start rather than failing later as a 500 on every authenticated route.
# No var/log: the loggers write to stdout and stderr, so the container's output
# is the log and nothing here has to be collected from a filesystem afterwards.
RUN mkdir -p var/cache var/caddy/config var/caddy/data \
    && chown -R www-data:www-data var

# Caddy keeps its autosaved config and certificate storage under the XDG
# directories, which default to root-owned paths this image does not run as.
# Nothing here needs either - the admin API is off and TLS terminates upstream -
# but without somewhere writable it logs a permission error on every start, and
# a production image whose first output is an error trains people to ignore its
# logs.
ENV XDG_CONFIG_HOME=/app/var/caddy/config \
    XDG_DATA_HOME=/app/var/caddy/data

COPY docker/php.production.ini /usr/local/etc/php/conf.d/production.ini

# Non-root, which is why the Caddyfile listens on 8080 rather than 80: binding
# a privileged port would need a capability this container is better without.
USER www-data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/api/health || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
