# Lottery syndicate — API

Backend API for administering a **Lotto 6 aus 49 syndicate**.
PHP 8.4, no framework, onion architecture with event sourcing and CQRS, MariaDB,
authentication through Keycloak (OIDC).

**Expansion stage base:** participants read exclusively their own data, the administrator
records everything. Expansion stages E1 (self-service) and E2 (sports betting) are
specified but not implemented — see [USER_STORIES.md](USER_STORIES.md).

## Documentation

| Document | Contents |
|---|---|
| [USER_STORIES.md](USER_STORIES.md) | **The domain reference.** Domain, stories, acceptance criteria, state of implementation |
| [AGENTS.md](AGENTS.md) | Working guide for developers and AI agents |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Layers, patterns, class map, open points |
| [KEYCLOAK.md](KEYCLOAK.md) | Authentication, demo users, tokens |
| [DOCKER.md](DOCKER.md) | Docker stack, configuration, troubleshooting |
| [QUICKSTART.md](QUICKSTART.md) | First steps, playing a tipp year through by hand |
| [PSR.md](PSR.md) | PSR standards: state and use |
| [CHANGELOG.md](CHANGELOG.md) | Chronicle of the larger rebuilds |

Machine-readable: [betting_game_api.yaml](betting_game_api.yaml) (OpenAPI 3.0.3, v2.3.0),
[betting_game_er_extended.mermaid](betting_game_er_extended.mermaid),
[database/schema.sql](database/schema.sql).

## The domain in five sentences

- A **tipp year** (`TippYear`) is a freely defined period, not a calendar year.
- It falls into non-overlapping **bet periods** (`BetPeriod`) — their length is
  configuration, not an assumption in code. One period spanning the whole year is allowed.
- Each participant has **exactly one bet row per period** (`BetRow`), of six numbers.
- Once a month the syndicate submits a shared **ticket** (`Ticket`): a snapshot of all valid
  rows. Its cost is the rows plus a **Bearbeitungsentgelt** charged once per Spielauftrag,
  and it creates one **fee** (`Fee`) per participant.
- **Draws** (`Draw`) produce winnings for the ticket as a whole; they are collected over the
  year and distributed **evenly across all participants** at the end of it.

## Architecture

### Layers

```
Presentation    controllers, router, kernel, HTTP helpers
     ↓
Application     commands + handlers, queries + handlers, projection manager
     ↓
Domain          aggregates, value objects, events, repository interfaces
     ↑
Infrastructure  implements the domain interfaces (PDO, event store, auth, cache)
```

`src/Domain/` has no outward dependency — no PDO, no HTTP, no PSR packages.
Dependencies always point inwards; infrastructure satisfies the interfaces the domain
prescribes.

### Request flow

```
public/index.php          globals → request object, build the container
  └─ Kernel::handle()     src/Presentation/Http/Kernel.php
       ├─ Router          FastRoute
       ├─ AuthMiddleware  except where 'public' => true; JWT verified against the realm's JWKS
       ├─ Authorization   where 'role' => 'admin'
       ├─ command_log     where 'command' => true (idempotency key, OPS-01/OPS-02)
       ├─ Controller      Input::* validates, command/query DTO, handler
       └─ ErrorMapper     domain exception → HTTP status
```

The `Kernel` is testable without a web server; `index.php` is only the bridge to the PHP
globals. A route is **authenticated by default** — a forgotten flag does not accidentally
make it public.

### Event sourcing and CQRS

- **Write path:** the handler loads the aggregate → domain logic → the aggregate records
  events → the repository writes events **and** projection in **one** transaction under
  optimistic locking.
- **Read path:** query handlers read the projection tables directly. No events, no joins
  across the event store.
- **Two paths to the same tables:** repositories write their projection *synchronously*
  while saving. The seven projectors in `src/Infrastructure/Projection/` are the second
  path — they replay the event log (`POST /admin/projections/{name}/rebuild`).
  That both produce the same rows is checked by
  [ProjectionRebuildTest](tests/Integration/Application/ProjectionRebuildTest.php) across all
  13 read-model tables.
- **Honest about asynchrony:** the API describes commands as asynchronous (`202`), the
  implementation writes synchronously. By the time the `202` arrives the command is already
  `completed`, and `projectionsUpToDate` is always `true`.

### Error mapping

Handlers throw domain exceptions and know nothing about HTTP.
[ErrorMapper](src/Presentation/Http/ErrorMapper.php) is the single point of translation:

| Exception | Status |
|---|---|
| `UnauthorizedAccessException` | 403 |
| `EntityNotFoundException` | 404 |
| `InvalidArgumentException`, `InvalidInputException` | 400 |
| `ConcurrencyException` | 409 |
| `BusinessRuleViolationException` (incl. `DuplicateEntryException`) | 409 |
| everything else | 500 (message only in debug mode) |

A rejected unique key is a business rule saying no — not a database error.
`EventSourcedRepository` therefore translates SQLSTATE 23000 into `DuplicateEntryException`.

## Endpoints

25 routes. The story IDs refer to [USER_STORIES.md](USER_STORIES.md).

### Participant — read only

| Endpoint | Story |
|---|---|
| `GET /participants/{id}/bet-row` | B-01 own bet row |
| `GET /participants/{id}/memberships` | B-02 own memberships |
| `GET /participants/{id}/fees` | B-03 own fees |
| `GET /participants/{id}/payout-share` | B-04 own share of the yearly distribution |
| `GET /tipp-years/{id}/draws` | B-05 the ticket's winnings per draw |

Identity comes from the token, never from the path. `Authorization::requireSelf()` rejects
someone else's `participantId` with `403` — for an admin too, who has their own endpoints
for that.

### Administrator

| Endpoint | Story |
|---|---|
| `GET` / `POST /admin/participants` | B-21 list and create participants |
| `PUT /admin/participants/{id}/bet-row` | B-06 assign a bet row |
| `GET /admin/fees` | B-07 the fee situation |
| `PUT /admin/fees/{feeId}/payment` | B-07 set the payment status |
| `POST /admin/draws` | B-08 record a draw |
| `PUT /admin/draws/{drawId}/winnings` | B-09 record the winnings of a draw |
| `GET` / `POST /admin/tipp-years` | B-10 tipp years |
| `PUT /admin/tipp-years/{id}/status` | B-18 set the status — every transition, but only one running year |
| `GET` / `POST /admin/tipp-years/{id}/bet-periods` | B-14 bet periods |
| `POST /admin/tipp-years/{id}/members` | B-11 add participants |
| `POST /admin/tipp-years/{id}/tickets` | B-12 submit the ticket |
| `POST /admin/tipp-years/{id}/payout` | B-13 record the yearly distribution |

### Operations

| Endpoint | Story |
|---|---|
| `GET /commands/{commandId}` | OPS-01 processing state of a command |
| `GET /admin/audit/{type}/{id}` | OPS-03 event history of an aggregate |
| `GET /admin/projections` | OPS-04 monitor projections |
| `POST /admin/projections/{name}/rebuild` | OPS-04 rebuild a projection |

`GET /commands/{commandId}` is deliberately not admin-protected: whoever issued the command
may look, and nobody can guess the UUID.

### Public

`GET /health` — the only endpoint without authentication. A health check behind a token
cannot tell a load balancer whether the service is running. It is therefore also absent
from the OpenAPI specification (21 paths, 24 operations).

## Authentication

The API expects a bearer token from Keycloak:

```http
Authorization: Bearer <jwt>
```

Verification happens in this order: `alg` against an allowlist that can only contain
asymmetric algorithms → signature against the public key from the realm's JWKS →
`exp`/`nbf`/`iat` with leeway → `iss` verbatim → `aud`, where configured.

- `participant_id` (custom claim) — for the participant endpoints
- `realm_access.roles` contains `admin` — for the admin endpoints

Because the signature is verified, these are assertions made by Keycloak and not by the
caller. **An unreachable Keycloak makes the API answer 503, not 401** — a key problem is not
an invalid token. Details: [KEYCLOAK.md](KEYCLOAK.md).

There is deliberately no JWT shared secret. Tokens are RS256; an application that
additionally accepts HS256 can be attacked with the very key it publishes itself.

## Examples

Get a token (demo users from the realm export):

```bash
TOKEN=$(curl -s -X POST http://localhost:8090/realms/betting-game/protocol/openid-connect/token \
  -d client_id=betting-game-frontend -d grant_type=password \
  -d username=admin -d password=admin123 | jq -r .access_token)
```

Create a tipp year (a command, answers `202`):

```bash
curl -X POST http://localhost:8080/admin/tipp-years \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"name":"Tippjahr 2026","startDate":"2026-01-01","endDate":"2026-12-31","ticketCostPerRow":1.20,"processingFeeSingleWeek":0.60,"processingFeeMultiWeek":1.00}'
```

```json
{
  "commandId": "8f14e45f-ceea-467a-9575-6a1d3a6bd0e1",
  "status": "accepted",
  "resourceId": 1,
  "timestamp": "2026-07-29T10:00:00+00:00"
}
```

Assign a bet row:

```bash
curl -X PUT http://localhost:8080/admin/participants/2/bet-row \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"betPeriodId":1,"numbers":[3,7,12,25,38,44]}'
```

Read one's own row (with the token of the participant whose `participant_id` is 2):

```bash
curl http://localhost:8080/participants/2/bet-row -H "Authorization: Bearer $TOKEN"
```

A retry with a known `Idempotency-Key` returns the stored response with its original status
code and the header `Idempotent-Replay: true`.

A complete run from an empty tipp year to the distribution is in
[QUICKSTART.md](QUICKSTART.md).

## Data model

20 tables in [database/schema.sql](database/schema.sql).

**Read model (13, built from events):** `participant`, `tipp_year`, `membership`,
`bet_period`, `bet_row`, `ticket`, `ticket_row`, `draw`, `ticket_draw_result`,
`ticket_row_match`, `fee`, `payout`, `payout_share`.

**Event sourcing (5):** `event_store` (the immutable event log, source of truth),
`event_stream` (stream metadata with version), `snapshot`, `projection_state`,
`event_publisher` (outbox, prepared).

**Operations (1):** `command_log` — command history and idempotency keys.

The `user` table dates from before Keycloak and is no longer written by any projector;
identities live in the realm.

## Installation

### With Docker (the normal case)

```bash
docker-compose up -d
docker-compose exec php composer install
curl http://localhost:8080/health
```

| Service | URL | Access |
|---|---|---|
| API (Caddy) | http://localhost:8080 | bearer token |
| PHPMyAdmin | http://localhost:8081 | root / secret |
| Keycloak | http://localhost:8090 | admin console `/admin`, admin / admin |
| MariaDB | localhost:3306 | root / secret, DB `betting_game` |
| Frontend (Vue SPA) | http://localhost:3000 | login through Keycloak, see [FRONTEND.md](FRONTEND.md) |

On its first start Keycloak needs 30–60 seconds for the realm import
(`docker-compose logs -f keycloak`). The stack is described in [DOCKER.md](DOCKER.md).

### Without Docker

Prerequisites: PHP 8.4 with `pdo_mysql`, MariaDB 11.4 or MySQL 8.0, Composer 2.

```bash
composer install
mysql -u root -p -e "CREATE DATABASE betting_game CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p betting_game < database/schema.sql
cp .env.example .env          # config/config.php reads everything from environment variables
php -S localhost:8080 -t public
```

For Apache or Nginx every path has to point at `public/index.php`; in the Docker stack
`docker/Caddyfile` takes care of that.

## Tests and code quality

Tests run in a dedicated environment with its own database; read-only checks are harmless
in the dev container:

```bash
make test-db-start        # MariaDB 11.4 on port 3307
make test-docker          # phpunit --testdox
make test-db-stop

docker-compose exec php vendor/bin/phpstan analyse
docker-compose exec php vendor/bin/phpcs --standard=PSR12 src tests public config
```

> The integration suite truncates every table before each test. In the `php` container
> `DB_DATABASE` points at the development database, which is why `IntegrationTestCase`
> rejects anything that does not end in `_test` and skips itself.

Without Docker the same targets are available as `make test`, `make phpstan`,
`make cs-check` — they assume a PHP in the PATH. `make all-tests` bundles all three.

All of this runs automatically in [`.github/workflows/ci.yml`](.github/workflows/ci.yml):
static analysis, PHPUnit against a MariaDB, ESLint + Vitest and the Playwright pass against
the full stack.

| Suite | Scope | Prerequisite |
|---|---|---|
| `tests/Unit` | 21 files, 235 test methods — domain logic, value objects, JWT, HTTP helpers | none |
| `tests/Integration` | 17 files, 195 test methods — repositories, command flows, HTTP chain, projection rebuild | MariaDB |

The integration tests **skip themselves** when no database is reachable. A green suite
without a running database therefore says nothing about persistence.
Start the test database: `make test-db-start`, remove it again: `make test-db-stop`.

- **PHPStan level 10** on `src`, clean (`phpstan.neon`, `treatPhpDocTypesAsCertain: false`)
- **PSR-12**, `declare(strict_types=1);` in every file
- 155 files under `src/`, one class per file

## Dependencies

**Production** — seven packages, no framework:

| Package | Purpose |
|---|---|
| `nikic/fast-route: ^1.3` | compiled routing |
| `php-di/php-di: ^7.0` | DI container, compiled in production |
| `ramsey/uuid: ^4.7` | command IDs |
| `monolog/monolog: ^3.5` | PSR-3 implementation |
| `psr/log`, `psr/container`, `psr/simple-cache` | interface packages |

Plus `ext-pdo` and `ext-json`. **Development:** `phpunit/phpunit: ^11.0`,
`phpstan/phpstan: ^2.1`, `squizlabs/php_codesniffer: ^3.8`.

## Leftovers in the repository

The change of course to the lottery domain (`f1d0771`) did not take everything along:

| What | State |
|---|---|
| [betting_game_api_e2_sports.yaml](betting_game_api_e2_sports.yaml), [database/schema-e2-sports.sql](database/schema-e2-sports.sql) | Deliberately kept for expansion stage E2 |
| `docker/Caddyfile.minimal`, `Caddyfile.alternative`, `php-fpm.conf.minimal` | Remnants of troubleshooting; only the ones mounted from `docker-compose.yml` are active |

## Licence

MIT. A `LICENSE` file is not yet part of the repository.
