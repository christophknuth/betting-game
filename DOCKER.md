# Docker Setup Guide - Betting Game API

## 🐳 Stack Overview

The application uses a modern, high-performance Docker stack:

### Services

| Service | Image | Port | Description |
|---------|-------|------|-------------|
| **PHP** | `php:8.3-fpm-alpine` (built from `docker/Dockerfile.php`) | 9000 (internal) | PHP-FPM with OPcache optimization |
| **Caddy** | `caddy:2.7-alpine` | 8080, 8443 | Modern web server with auto-HTTPS |
| **MariaDB** | `mariadb:11.3` | 3306 | Latest stable database |
| **PHPMyAdmin** | `phpmyadmin:latest` | 8081 | Database management UI |
| **Frontend** | built from `frontend/Dockerfile` | 3000 | ⛔ Legacy Vue.js 3 SPA, see below |
| **Keycloak** | `quay.io/keycloak/keycloak:23.0` | 8090 | OAuth2/OIDC identity provider |
| **Keycloak DB** | `postgres:16-alpine` | — (internal) | PostgreSQL for Keycloak |

> ⛔ **The `frontend` service is legacy.** It serves the SPA of the sports prediction game
> this project used to be; none of its endpoints exist in the API any more. Nothing else in
> the stack depends on it — `docker-compose stop frontend` is safe. See
> [FRONTEND.md](FRONTEND.md).

### Why This Stack?

#### PHP-FPM 8.3 Alpine
- **Smaller footprint**: Alpine Linux is ~5MB vs ~100MB+ for Debian
- **Faster startup**: Minimal dependencies
- **Better performance**: Optimized process manager
- **JIT compilation**: PHP 8.3 JIT for performance boost
- **OPcache enabled**: Code cache for maximum speed

#### Caddy 2.7
- **Automatic HTTPS**: Free SSL certificates via Let's Encrypt
- **Modern**: HTTP/2 and HTTP/3 support
- **Simple config**: No complex directives
- **Performance**: Built in Go, optimized for speed
- **Zero downtime**: Graceful reloads
- **Built-in compression**: Gzip and Zstd

#### MariaDB 11.3
- **Latest stable**: Most recent features and fixes
- **Better performance**: Optimized query execution
- **Enhanced security**: Latest security patches
- **Full MySQL compatibility**: Drop-in replacement
- **InnoDB optimizations**: Faster transactions

## 🚀 Quick Start

### First Time Setup

```bash
# Clone/Extract project
cd betting-game

# Start all services
make start

# Wait for services (automatic with make start)
# Install dependencies
make composer-install

# Verify installation
curl http://localhost:8080/health
```

### Daily Development

```bash
# Start services
make start

# View logs
make logs

# Stop services
make stop

# Restart services
make restart
```

## 📁 Docker Configuration Files

### docker-compose.yml
Main orchestration file defining all services and their relationships.

**Key features:**
- Named volumes for persistence
- Custom network for service isolation
- Health checks (planned)
- Optimized MariaDB configuration

### docker/Dockerfile.php
Custom PHP-FPM image with:
- Required extensions (PDO, MySQL, Zip, OPcache)
- Composer pre-installed
- Proper file permissions
- Production-ready configuration

### docker/Caddyfile
Caddy web server configuration:
- PHP-FPM proxy to PHP container
- Automatic routing for front controller
- Security headers
- CORS support
- Gzip compression
- Access logging

### docker/php-fpm.conf
PHP-FPM pool configuration (mounted as `zz-custom.conf` via `docker-compose.yml`):
- Dynamic process management
- 20 max children (adjustable)
- Request timeout: 30s
- Worker output forwarded to the container log (`catch_workers_output`)
- Error log to stderr, memory limit 256M

Slow log and access log were removed – see [Known configuration pitfalls](#known-configuration-pitfalls).

### docker/php.ini
PHP runtime configuration:
- OPcache optimization
- 256MB memory limit
- Error reporting (development)
- Realpath cache for performance
- JSON strict mode

## 🔧 Common Commands

### Container Management

```bash
# Start services in background
make start
# or
docker-compose up -d

# Build/rebuild containers
make build
# or
docker-compose build --no-cache

# Stop services
make stop
# or
docker-compose down

# Restart services
make restart
# or
docker-compose restart

# View service status
docker-compose ps
```

### Logs

```bash
# All services
make logs

# Specific service
make logs-php
make logs-caddy
make logs-db

# Follow logs
docker-compose logs -f php

# Last 100 lines
docker-compose logs --tail=100 php
```

### Shell Access

```bash
# PHP container
make php-shell
# or
docker-compose exec php sh

# Database
make db-shell
# or
docker-compose exec db mysql -uroot -psecret betting_game

# Caddy
make caddy-shell
```

### Database Operations

```bash
# Reset database (reload schema)
make db-reset

# Backup database
docker-compose exec db mysqldump -uroot -psecret betting_game > backup.sql

# Restore database
docker-compose exec -T db mysql -uroot -psecret betting_game < backup.sql

# Access PHPMyAdmin
open http://localhost:8081
```

### Composer

```bash
# Install dependencies
make composer-install

# Update dependencies
make composer-update

# Add package
docker-compose exec php composer require vendor/package

# Remove package
docker-compose exec php composer remove vendor/package
```

### Testing

```bash
# Run tests in their own environment, with their own database.
# Not in the `php` container: its DB_DATABASE is the development database, and
# the integration suite truncates every table before each test. IntegrationTestCase
# refuses any database not named *_test and skips instead.
make test-db-start
make test-docker
make test-db-stop

# With coverage
docker-compose -f docker-compose.test.yml run --rm test vendor/bin/phpunit --coverage-text

# PHPStan (level 10, see phpstan.neon) - read-only, safe in the dev container
docker-compose exec php vendor/bin/phpstan analyse

# PSR-12
docker-compose exec php vendor/bin/phpcs --standard=PSR12 src tests public config
```

The integration tests use the `db` service and **skip themselves** when no database is
reachable — a green run without one proves nothing about persistence.

For a test run without the full stack there is `docker/Dockerfile.test` (PHP 8.3 CLI +
`pdo_mysql` + `pcov`). It is deliberately not wired into any compose file and is built and
run directly.

## ⚙️ Configuration

### Environment Variables

Edit `docker-compose.yml` to change:

```yaml
environment:
  - APP_ENV=development        # development, production
  - APP_DEBUG=true             # true, false
  - DB_HOST=db                 # database host
  - DB_DATABASE=betting_game   # database name
  - DB_USERNAME=root           # database user
  - DB_PASSWORD=secret         # database password
```

Or use `.env` file:

```bash
# Create .env file
cp .env.example .env

# Edit .env
nano .env

# Restart to apply
make restart
```

### PHP Configuration

Edit `docker/php.ini`:
- Memory limits
- Upload sizes
- OPcache settings
- Error reporting

**Restart PHP container after changes:**
```bash
docker-compose restart php
```

### PHP-FPM Pool

Edit `docker/php-fpm.conf`:
- Process manager settings
- Max children
- Request timeout
- Logging

### Caddy Configuration

Edit `docker/Caddyfile`:
- Virtual hosts
- SSL settings
- Routing rules
- Headers

**Reload Caddy (zero downtime):**
```bash
docker-compose exec caddy caddy reload --config /etc/caddy/Caddyfile
```

### MariaDB Tuning

Edit `docker-compose.yml` database command:
```yaml
command: >
  --character-set-server=utf8mb4
  --collation-server=utf8mb4_unicode_ci
  --max-connections=200
  --innodb-buffer-pool-size=512M    # Adjust based on RAM
  --innodb-log-file-size=128M
```

## 🚀 Performance Optimization

### Development vs Production

**Development (default):**
- OPcache revalidation: 2s
- Error display: On
- Debug mode: On
- Source maps: Enabled

**Production:**

1. **Update `docker/php.ini`:**
```ini
display_errors = Off
opcache.validate_timestamps = 0
opcache.revalidate_freq = 0
```

2. **Update environment:**
```yaml
environment:
  - APP_ENV=production
  - APP_DEBUG=false
```

3. **Enable Caddy HTTPS:**
Edit `docker/Caddyfile` and uncomment HTTPS section.

4. **Rebuild containers:**
```bash
make build
make start
```

### Resource Allocation

**For 8GB RAM system:**
```yaml
# MariaDB
innodb-buffer-pool-size=1G

# PHP-FPM
pm.max_children = 50
memory_limit = 256M
```

**For 16GB+ RAM system:**
```yaml
# MariaDB
innodb-buffer-pool-size=4G

# PHP-FPM
pm.max_children = 100
memory_limit = 512M
```

## 🔍 Troubleshooting

### First steps

```bash
docker-compose ps                                              # all services "Up"?
docker-compose logs caddy | grep -i error
docker-compose logs php   | grep -i error
docker-compose exec php php-fpm -t                             # config test
docker-compose exec caddy caddy validate --config /etc/caddy/Caddyfile
docker-compose exec caddy nc -z php 9000                       # network reachable?
curl http://localhost:8080/health                              # API answers?
```

Expected containers: `betting-game-caddy`, `-php`, `-db`, `-frontend`, `-phpmyadmin`,
`-keycloak`, `-keycloak-db`.

### Known configuration pitfalls

Both issues below are already fixed in the repository — they are documented because the
error messages are misleading.

**Caddy: `unrecognized subdirective split_path`**

```diff
- php_fastcgi php:9000 {
-     split_path .php
- }
+ php_fastcgi php:9000
```

In Caddy 2 the subdirective is called `split`, not `split_path` — and the high-level
`php_fastcgi` directive handles the front-controller pattern, FastCGI parameters, index
handling and `PATH_INFO` on its own, so it is not needed here.

**PHP-FPM: `unknown entry 'process_priority'`**

The correct name is `process.priority` (with a dot). Also removed from the pool config:
`request_slowlog_timeout`, `slowlog`, `listen.backlog`, `access.log`, `access.format` —
all valid directives, but they need a writable log directory that does not exist in the
Alpine image.

Watch out: `rlimit_files` and `rlimit_core` **are** valid PHP-FPM directives (contrary to
what older notes in this repo claimed), they may just fail on missing container
permissions. When in doubt, `php-fpm -t` tells you whether a directive is accepted.

**Fallback configurations**

```bash
cp docker/Caddyfile.minimal    docker/Caddyfile      # or Caddyfile.alternative
cp docker/php-fpm.conf.minimal docker/php-fpm.conf
docker-compose restart

# or use the helper scripts
make fix-caddy
make fix-php-fpm
make fix-all
```

To run PHP-FPM without the custom pool config, comment out its mount in
`docker-compose.yml` (the file is mounted, not baked into the image):

```yaml
    volumes:
      - .:/var/www/html
      # - ./docker/php-fpm.conf:/usr/local/etc/php-fpm.d/zz-custom.conf
      - ./docker/php.ini:/usr/local/etc/php/conf.d/custom.ini
```

Then `docker-compose up -d --force-recreate php`.

### 502 Bad Gateway

Caddy runs but cannot reach PHP-FPM:

```bash
docker-compose exec php php-fpm -t
docker-compose exec caddy nc -z php 9000
docker network inspect betting-game-network    # caddy and php in the same network?
docker-compose restart php caddy
```

### Port Already in Use

```bash
# Check what's using port 8080
lsof -i :8080
sudo netstat -tlnp | grep 8080

# Kill process
kill -9 <PID>

# Or change port in docker-compose.yml
ports:
  - "8888:80"  # Use 8888 instead
```

### Permission Denied

```bash
# Fix file permissions
docker-compose exec php chown -R www-data:www-data /var/www/html

# Fix local permissions
sudo chown -R $USER:$USER .
```

### Container Won't Start

```bash
# Check logs
docker-compose logs php

# Rebuild from scratch
docker-compose down -v
docker-compose build --no-cache
docker-compose up -d

# Check container status
docker-compose ps
docker inspect betting-game-php
```

### Database Connection Failed

```bash
# Verify database is running
docker-compose ps db

# Check database logs
docker-compose logs db

# Test connection
docker-compose exec php php -r "new PDO('mysql:host=db;dbname=betting_game', 'root', 'secret');"
```

### PHP extensions missing

```bash
docker-compose exec php php -m     # expect pdo, pdo_mysql, zip, opcache
```

If one is missing, rebuild: `docker-compose build --no-cache php`.

### PHP-FPM tuning profiles

```ini
; Development (default)
pm = dynamic
pm.max_children = 20
pm.start_servers = 4

; Production (more RAM)
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 15

; Low-RAM systems
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 10s
```

Optional status page — add `pm.status_path = /status` to `docker/php-fpm.conf`, then
`curl http://localhost:8080/status`.

### Caddy debugging

Enable verbose output temporarily in `docker/Caddyfile`:

```
{
  debug
}

:80 {
  # ...
  log {
    output stdout
    format console
    level DEBUG
  }
}
```

Reload without downtime:

```bash
docker-compose exec caddy caddy reload --config /etc/caddy/Caddyfile
docker-compose exec caddy caddy version      # expect v2.7.x
```

### Collecting support information

```bash
docker --version && docker-compose --version
docker-compose ps            > container-status.txt
docker-compose logs caddy    > caddy-logs.txt
docker-compose logs php      > php-logs.txt
docker-compose exec php php -i > phpinfo.txt
```

### Composer Issues

```bash
# Clear cache
docker-compose exec php composer clear-cache

# Update Composer
docker-compose exec php composer self-update

# Reinstall dependencies
docker-compose exec php rm -rf vendor
docker-compose exec php composer install
```

### OPcache Not Working

```bash
# Check OPcache status
docker-compose exec php php -i | grep opcache

# Restart PHP-FPM
docker-compose restart php

# Verify in phpinfo
docker-compose exec php php -r "phpinfo();" | grep opcache
```

## 📊 Monitoring

### Resource Usage

```bash
# Container stats
docker stats

# Specific container
docker stats betting-game-php

# Memory usage
docker-compose exec php free -m

# Disk usage
docker system df
```

### Application Health

```bash
# API health check
curl http://localhost:8080/health

# PHP-FPM status (requires pm.status_path in php-fpm.conf, see Troubleshooting)
curl http://localhost:8080/status

# Database status
docker-compose exec db mysqladmin -uroot -psecret status
```

### Logs Analysis

```bash
# PHP errors
docker-compose logs php | grep -i error

# Database errors
docker-compose logs db | grep -i error

# Caddy access log
docker-compose logs caddy | grep "GET"
```

## 🔐 Security

### Production Checklist

- [ ] Change default database password
- [ ] Disable PHPMyAdmin or protect with password
- [ ] Enable HTTPS in Caddyfile
- [ ] Set `APP_DEBUG=false`
- [ ] Set `display_errors=Off`
- [ ] Configure firewall rules
- [ ] Regular security updates
- [ ] Backup strategy implemented
- [ ] Monitor logs for suspicious activity

### Update Images

```bash
# Pull latest images
docker-compose pull

# Rebuild
docker-compose up -d --build

# Remove old images
docker image prune -a
```

## 🆘 Getting Help

**Check logs first:**
```bash
make logs
```

**Common log locations:**
- PHP errors: `docker-compose logs php`
- Database: `docker-compose logs db`
- Caddy: `docker-compose logs caddy`

**Test individual components:**
```bash
# PHP
docker-compose exec php php -v

# Database connection
make db-shell

# Web server
curl -I http://localhost:8080
```

**Reset everything:**
```bash
make fresh
```

(`fresh` runs `clean` first, so calling `make clean` beforehand is unnecessary.)

This will:
1. Stop all containers
2. Remove volumes
3. Remove vendor/
4. Start fresh
5. Install dependencies

**Full reset (also removes unrelated Docker data — use with care):**

```bash
docker-compose down -v
docker system prune -a
docker-compose build --no-cache
docker-compose up -d
```

---

**Need more help?** See [README.md](README.md), [QUICKSTART.md](QUICKSTART.md),
[KEYCLOAK.md](KEYCLOAK.md) or [CHANGELOG.md](CHANGELOG.md) for the history of this stack.
