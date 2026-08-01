# AGENTS.md

Working guide for AI agents and new developers in this repository.
Tool-neutral — it applies to every agent. Claude Code specifics are in
[CLAUDE.md](CLAUDE.md).

---

## 1. What this project is

A backend API for administering a **lottery syndicate playing Lotto 6 aus 49**.
PHP 8.4, no framework, onion architecture with event sourcing and CQRS.

**The core domain idea** (in full in [USER_STORIES.md](USER_STORIES.md)):

- A **tipp year** (`TippYear`) is a freely defined period, not a calendar year.
- It falls into non-overlapping **bet periods** (`BetPeriod`). Their length is
  configuration, not an assumption in code — one period spanning the whole year is allowed.
- Each participant has **exactly one bet row per period** (`BetRow`), of six numbers.
- Once a month a shared **ticket** (`Ticket`) is submitted: a snapshot of all valid rows.
  Its cost is `rows × draws × price` plus a **Bearbeitungsentgelt** charged once for the
  Spielauftrag, at a rate the tipp year's price list sets by the ticket's length. It creates
  one **fee** (`Fee`) per participant.
- **Draws** (`Draw`) produce winnings for the ticket as a whole; they are collected over the
  year and distributed **evenly across all participants** at the end of it.

**Expansion stage: base.** Participants only read, the administrator writes everything.
E1 (self-service) and E2 (sports betting) are specified but not implemented.

### Roles

| Role | Keycloak role | Access |
|---|---|---|
| Participant | `user` | Exclusively their own data, read only |
| Administrator / operator | `admin` | All write operations, the operations view |

---

## 2. Which documents are current

The project was moved from a sports-betting game to the lottery with commit `f1d0771`
("Refocus the project on the Lotto 6aus49 syndicate domain"). The documentation was caught
up on 2026-07-29.

| Document | State |
|---|---|
| [USER_STORIES.md](USER_STORIES.md) | ✅ **Current and authoritative.** The domain reference, including per-story status |
| [betting_game_api.yaml](betting_game_api.yaml) | ✅ **Current** (v2.3.0, "Lottery Syndicate API"). The authoritative API contract |
| [betting_game_er_extended.mermaid](betting_game_er_extended.mermaid) | ✅ Current |
| [database/schema.sql](database/schema.sql) | ✅ Current |
| [README.md](README.md) | ✅ Current. Overview, endpoints, installation |
| [ARCHITECTURE.md](ARCHITECTURE.md) | ✅ Current. Layers, class map, open points |
| [QUICKSTART.md](QUICKSTART.md) | ✅ Current. A tipp year played through, and since B-18/B-21 without reaching into the database |
| [KEYCLOAK.md](KEYCLOAK.md) | ✅ Current |
| [PSR.md](PSR.md) | ✅ Current. Mind the note before reading: implemented ≠ used |
| [DOCKER.md](DOCKER.md) | ✅ Current, domain-neutral |
| [.github/workflows/ci.yml](.github/workflows/ci.yml) | ✅ Current. Four jobs, see section 5 |
| [CHANGELOG.md](CHANGELOG.md) | ✅ Current up to `dbe1b95` |
| [FRONTEND.md](FRONTEND.md), [frontend/](frontend/) | ✅ Current. The Vue SPA has been moved onto the lotto endpoints (12 views, the view → endpoint table is in FRONTEND.md). Vitest + Playwright |
| [betting_game_api_e2_sports.yaml](betting_game_api_e2_sports.yaml), [database/schema-e2-sports.sql](database/schema-e2-sports.sql) | 📦 Deliberately kept for expansion stage E2, not implemented |

`DEMO.md` described a `demo/` directory that disappeared with the change of course, and was
deleted.

**Rule:** where things contradict, the code wins, then `USER_STORIES.md` and the OpenAPI
spec. Whoever touches a document also corrects what is out of date instead of perpetuating
it — and updates the numbers (files, tests, routes) instead of copying them over.

---

## 3. Architecture

### Layers (dependencies always point inwards)

```
Presentation   controllers, router, kernel, HTTP helpers
     ↓
Application    commands + handlers, queries + handlers, projection manager
     ↓
Domain         models (aggregates), value objects, events, repository interfaces
     ↑
Infrastructure implements the domain interfaces (PDO, event store, auth, cache)
```

`src/Domain/` has **no** outward dependency — no PDO, no HTTP, no PSR beyond the language
itself. Anyone writing a `use BettingGame\Infrastructure\…` in there has broken the
architecture.

### Request flow

```
public/index.php          globals → request object, build the container
  └─ Kernel::handle()     src/Presentation/Http/Kernel.php  ← the whole sequence is here
       ├─ Router          FastRoute, routing table in src/Presentation/Router/Router.php
       ├─ AuthMiddleware  except where 'public' => true. JWT verified against the realm's JWKS
       ├─ Authorization   where 'role' => 'admin'
       ├─ command_log     where 'command' => true (idempotency key, OPS-01/OPS-02)
       ├─ Controller      Input::* validates, command/query DTO, handler
       └─ ErrorMapper     domain exception → HTTP status
```

The `Kernel` is testable without a web server; `index.php` is only the bridge to the PHP
globals. New cross-cutting logic belongs in the kernel, not in `index.php` and not in
controllers.

### Route flags (`src/Presentation/Router/Router.php`)

| Flag | Effect |
|---|---|
| `'public' => true` | No authentication. **Only** `/health` |
| `'role' => 'admin'` | The kernel enforces the admin role before the controller |
| `'command' => true` | Runs under the command log and the idempotency key |
| (nothing) | Authenticated; the ownership check is the controller's job, through `Authorization::requireSelf()` |

A route is **authenticated by default**. A forgotten flag does not accidentally make it
public. Constrain path parameters with `{id:\d+}` so that a mistyped URL gives a 404 instead
of a 400 from the depths of the handler.

### Event sourcing / CQRS — how it actually runs here

- **Write path:** the handler loads the aggregate → domain logic → the aggregate records
  events (the `RecordsEvents` trait) → the repository writes events **and** projection in
  **one** transaction (`EventSourcedRepository::transactionally()`) under optimistic locking.
- **Read path:** the query handler reads the projection tables directly. No events, no joins
  across the event store.
- **Two paths to the same tables:** repositories write projections *synchronously*
  (a load right afterwards has to see them). The projectors in
  `src/Infrastructure/Projection/` are the *second* path — they replay the event log
  (`POST /admin/projections/{name}/rebuild`).
  **Both paths have to produce the same rows.**
  [ProjectionRebuildTest](tests/Integration/Application/ProjectionRebuildTest.php) plays a
  whole tipp year through, rebuilds from the event store and compares all 13 read-model
  tables row by row. Whoever changes a projection changes both sides.
- **Honest about asynchrony:** the API describes commands as asynchronous (`202`), the
  implementation writes synchronously. By the time the `202` arrives the command is already
  `completed`, and `projectionsUpToDate` is always `true`.
- **IDs:** `nextId()` is `MAX(id) + 1`. Under concurrency the unique key on the target table
  decides, not a check in code — the loser gets a `409` and retries.

### Error mapping (`src/Presentation/Http/ErrorMapper.php`)

Handlers throw domain exceptions and know nothing about HTTP. The single point of
translation:

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

---

## 4. Directory structure

```
src/                              155 files, one class per file
├── Domain/                       THE CORE — no outward dependencies
│   ├── Model/                    aggregates: TippYear, BetPeriod, BetRow, Ticket,
│   │                             Draw, Fee, Participant + the RecordsEvents trait
│   ├── ValueObject/              LottoNumbers, Superzahl, DateRange, EvenSplit,
│   │                             WinningClass, TippYearStatus, Email, DisplayName, …
│   ├── Event/                    DomainEvent + 14 concrete events
│   ├── Repository/               repository interfaces + RecordedEvent
│   ├── Service/                  WinningsDistribution (used by the handler AND the projector)
│   └── Exception/                the exception hierarchy under DomainException
├── Application/
│   ├── Command/                  9 commands + handlers, CommandResult
│   ├── Query/                    10 queries + handlers, QueryResult
│   └── Projection/               ProjectionManager, Projector, ProjectionStatus
├── Infrastructure/
│   ├── Auth/                     TokenVerifier, JwkSet, KeycloakKeys, AuthMiddleware
│   ├── Cache/                    FileCache, RedisCache (PSR-16)
│   ├── Config/                   Config (typed access to the config array)
│   ├── DI/                       Container (PHP-DI), PsrContainer (PSR-11)
│   ├── EventStore/               PdoEventStore
│   ├── Persistence/              Db + repositories, EventSourcedRepository as the base
│   ├── Projection/               7 projectors, one per read model
│   └── Logging/                  LoggerFactory (Monolog, PSR-3)
├── Presentation/
│   ├── Controller/               9 controllers
│   ├── Http/                     Kernel, Request, JsonResponse, Input, Authorization,
│   │                             ErrorMapper
│   └── Router/                   Router (FastRoute)
└── Support/                      Row (typed access to DB rows)

tests/Unit/                       without a database
tests/Integration/                needs MariaDB, otherwise skips itself
config/config.php                 all values from environment variables
database/schema.sql               20 tables (13 read model + event sourcing + ops)
docker/                           Dockerfile.php (FPM), Dockerfile.test (CLI+pcov), Caddyfile
keycloak/realm-export.json        realm, demo users, roles, the participant_id claim
```

---

## 5. Commands

**PHP does not have to be installed locally.** The normal case is Docker.
The `composer` and `make` targets below assume a local PHP and only work inside the
container or on a machine with PHP 8.4.

### Through Docker (the normal case here)

```bash
docker-compose up -d                              # the complete stack
docker-compose exec php composer install
docker-compose exec php vendor/bin/phpstan analyse
docker-compose exec php vendor/bin/phpcs --standard=PSR12 src tests public config
```

> **Do not test in the `php` container.** Its `DB_DATABASE` is `betting_game`, the
> development database — and the integration suite truncates **every** table before every
> test. `IntegrationTestCase` therefore refuses any database whose name does not end in
> `_test`, and skips itself with a note instead of deleting the dev data. Tests belong in
> the environment below.

For tests there is `docker-compose.test.yml`: a PHP 8.4 CLI image
(`docker/Dockerfile.test`, with `pdo_mysql` + `pcov`) against its own MariaDB, isolated from
the dev stack (`betting_game_test` on port 3307). `composer install` runs afresh on every
container start (see the comment in the Dockerfile) — which keeps the autoloader correct
even when `src/` has changed since the last run.

```bash
docker-compose -f docker-compose.test.yml up -d test-db
docker-compose -f docker-compose.test.yml run --rm test                                  # phpunit --testdox
docker-compose -f docker-compose.test.yml run --rm test vendor/bin/phpstan analyse
docker-compose -f docker-compose.test.yml down -v
```

Equivalently through `make test-db-start` / `test-docker` / `phpstan-docker` /
`test-db-stop`.

### Continuous integration

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on `main`, `Refocus-project`
and every pull request — in four separate jobs, so that fast feedback does not wait on the
slowest one:

| Job | Scope | Needs |
|---|---|---|
| `static` | PHPStan level 10, PSR-12 | nothing |
| `php-tests` | PHPUnit including integration | MariaDB as a service (`betting_game_test`) |
| `frontend-unit` | ESLint, Vitest | Node 18 |
| `e2e` | Playwright | the **full** stack including the Keycloak realm import |

**`--fail-on-skipped` is the most important switch in there.** Without a reachable database
the integration tests skip themselves and still report green — in CI that would be a lie
about persistence. The job provides a database, so any skip is a failure.

### Makefile / Composer (with a local PHP)

| Command | Effect |
|---|---|
| `make test` | All tests; the integration tests skip themselves without a DB |
| `make test-unit` | Only `tests/Unit` |
| `make test-integration` | Only `tests/Integration` (needs `make test-db-start`) |
| `make test-db-start` / `test-db-stop` | MariaDB 11.4 on port 3307 with `betting_game_test` + schema |
| `make coverage` | HTML report into `coverage/` |
| `make phpstan` | Static analysis, **level 10**, target `src` |
| `make cs-check` / `cs-fix` | Check / fix PSR-12 |
| `make all-tests` (= `quality`) | PHPStan + CS + tests |
| `make start` / `stop` / `logs` | The Docker stack |

### Services in the stack

| Service | URL | Access |
|---|---|---|
| API (Caddy) | http://localhost:8080 | |
| Frontend (Vue SPA) | http://localhost:3000 | |
| PHPMyAdmin | http://localhost:8081 | root / secret |
| Keycloak | http://localhost:8090 | admin / admin |
| MariaDB | localhost:3306 | root / secret, DB `betting_game` |

---

## 6. Conventions

### Code

- `declare(strict_types=1);` in **every** file under `src/` and `tests/`.
- **One class per file**, filename = class name, PSR-4 mapping 1:1 onto the namespace
  structure.
- `final` by default. Inheritance only with a reason (`EventSourcedRepository` is one of the
  few `abstract` bases).
- Value objects are **immutable** and validate in the constructor.
- Constructor property promotion, `match`, enum-like VOs — use the PHP 8.4 idioms.
- Do not read `$_ENV` directly: `config/config.php` goes through `getenv()`, because `$_ENV`
  is not populated in the official PHP images. Likewise no output before the response — a
  PHP warning sends the headers and turns every status code into 200.
- The namespace root stays `BettingGame\` (historically, despite the lotto domain).

### PHPStan level 10

`phpstan.neon` is set to level 10 with `treatPhpDocTypesAsCertain: false`. The code is
clean — **keep it that way**. In practice that means: `array<string, mixed>` from external
sources is checked explicitly (`is_int`, `is_string`, `assert(… instanceof …)`), never
blindly cast. That is what `Support\Row` (DB rows) and `Http\Input` (request bodies) are
for. No `@phpstan-ignore` without need.

### Comment style

The comments in this repo explain **why**, not what. Examples from the existing code:

> "The key is claimed *before* the command runs. Checking first and executing afterwards
> would leave a window in which two parallel retries both get through — exactly the double
> booking the key exists to prevent."

> "A route is authenticated unless it says otherwise. The other way round, a forgotten flag
> would silently make it public."

Write new comments in that form. Class docblocks name the story ID (`B-12`, `OPS-02`) the
class belongs to. No comments that restate the signature.

### Language

- **Everything that lands in the repository is written in English** — code, comments,
  docblocks, documentation and commit messages.
- **The one exception is the frontend's user-facing text.** Labels, messages, status words
  and the `de-DE` date and currency formatting stay German, because that is the language of
  the syndicate using the application. The same goes for sample data that mirrors what an
  administrator would actually type (`"Tippjahr 2026"` in tests and examples) and for the
  German product name *Lotto 6 aus 49*. A test asserting on a visible label therefore
  contains German — that is the assertion, not prose.
- Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/)
  (`type(scope): subject`, imperative, one line): `feat: verify the token signature`,
  `fix(auth): wire the base version up over HTTP`. The usual types here: `feat`, `fix`,
  `docs`, `refactor`, `test`, `chore`.

---

## 7. Tests

| Suite | Scope | Prerequisite |
|---|---|---|
| `tests/Unit` | 19 files, 213 test methods — domain logic, value objects, auth/JWT, HTTP helpers | none |
| `tests/Integration` | 17 files, 173 test methods — repositories, command flows, HTTP chain, projection rebuild | MariaDB |

386 test methods in total.

- Integration tests **skip themselves** when no database is reachable
  (`IntegrationTestCase::setUpBeforeClass()`). The suite therefore stays green without a DB —
  *so "all tests green" without a running DB does not mean persistence was checked.*
- Repositories are **not** tested against a mocked PDO. They are almost entirely SQL (unique
  keys, joins, upserts); a mock would only confirm that we wrote the strings we wrote.
- `HttpTestCase` / `ApplicationTestCase` drive the full chain kernel → controller →
  handler → repository against the real database.
- `tests/Support/SigningKey.php` creates tokens for the auth tests — no real Keycloak needed.

---

## 8. Adding a new feature

### A new command (write operation)

1. **The domain first.** Enforce the rule in the aggregate under `src/Domain/Model/`, and
   record an event there through `recordEvent()`. A new event goes into `src/Domain/Event/`.
2. **Command + handler** in `src/Application/Command/`. The handler loads through repository
   interfaces, calls domain methods, and returns `CommandResult::accepted()`. It throws
   domain exceptions, never HTTP.
3. **Persistence:** the repository (extending `EventSourcedRepository`) writes events and
   projection inside `transactionally()`. Add an interface in `src/Domain/Repository/` where
   needed.
4. **Bring the projector along** in `src/Infrastructure/Projection/`, so that a rebuild
   produces the same rows. Register a new projector in the list in `Container.php`
   (`ProjectionManager`).
5. **Route** in `Router.php` with `'command' => true` and, where applicable,
   `'role' => 'admin'`.
6. **Controller method**: read the body through `Input::*`, return
   `JsonResponse::accepted(...)`. Identities (who recorded it, whose data) come **from the
   token**, never from the path or the body.
7. **DI:** register the handler and controller in `src/Infrastructure/DI/Container.php`
   (usually `\DI\autowire()`).
8. **Schema:** extend `database/schema.sql` and `betting_game_er_extended.mermaid`.
9. **Tests:** a unit test for the domain rule, an integration test for the flow, and the
   rebuild test has to cover the new table too.
10. **Docs:** extend `betting_game_api.yaml`, set the status in `USER_STORIES.md`.

### A new query (read operation)

Query DTO + handler in `src/Application/Query/`, a repository method on the projection
tables, a route without the `command` flag, a controller method with
`Authorization::requireSelf()` for participant data, the DI binding, tests, OpenAPI.

---

## 9. Pitfalls

- **Do not forget `Authorization::requireSelf()`.** Identity comes from the token, never
  from `{participantId}` in the path — otherwise the rule checks itself (B-16).
  Deliberately strict enough that an admin does not get through here either: the admin has
  their own endpoints, otherwise the participant routes would be a second, quieter admin API.
- **Do not introduce a JWT shared secret.** Tokens are RS256 from Keycloak, verified against
  the JWKS endpoint. An application that additionally accepts HS256 can be attacked with the
  very key it publishes itself.
- **An unreachable Keycloak → 503, not 401.** A key problem is not an invalid token.
- **`ticket_row_match` appears in no event.** The projector recomputes the rows through the
  domain service `WinningsDistribution` — the same one the handler uses. That is exactly why
  it was extracted; do not duplicate the logic.
- **A rebuild reaches downwards.** Read models hang together through `ON DELETE CASCADE`
  (emptying `participant` empties `membership`, `bet_row`, `fee`). A rebuild rebuilds the
  dependent projections along with it.
- **Rows written by hand cannot survive a rebuild.**
  [`seed-demo-participants.sql`](database/seed-demo-participants.sql) inserts participants 1
  and 2 directly, because their IDs have to match the realm's `participant_id` claims. Those
  rows stand in no event, so rebuilding `participant_read_model` drops them — and then
  `membership` events referring to them fail on the foreign key, which takes the whole
  rebuild down with them. On a database seeded that way, rebuild from
  `tipp_year_read_model` instead and leave the participants alone.
- **The interface answers two questions; the log carries the rest.** Did it work, and if
  not what can the reader do about it. Command ids, the rule a rejection tripped, HTTP
  status codes and how something is computed belong in the container's output, which is
  where the `Kernel` puts them. The test for a sentence on screen: does it change what the
  reader *does*? Browser-side diagnostics go to the console — nothing in the SPA can reach
  the container log without an endpoint that would have to work before login.
- **A container definition may not capture the outer scope.** `APP_ENV=production` makes
  PHP-DI compile the container, and it cannot compile a closure that imports a variable —
  the whole bootstrap dies with *"Cannot compile closures which import variables using the
  `use` keyword"*. **Arrow functions capture implicitly**, so `fn () => new X($settings)`
  fails exactly the same way while surviving any search for `use (`. Resolve what you need
  from the container instead: `static function (PsrContainerInterface $c) { $settings =
  $c->get(Config::class); … }`. Nothing in the development stack notices, because
  compilation is off there.
- **Money is split with `EvenSplit`, never with `round($total / $n)`.** Since the
  Bearbeitungsentgelt is charged once per ticket rather than per row, a ticket's total is
  generally not a multiple of the row count — 33.40 across three is 11.1333… Rounding each
  share separately under-bills by a cent on every ticket. `EvenSplit` divides in whole cents
  and puts the remainder on the first share; B-09, B-12 and B-13 all use it.
- **Adding a field to an event is a schema change to an immutable log.** Events already
  written carry no such key, so the projector and `PdoEventStore`'s deserialiser have to read
  it with `Row::nullableFloat(...) ?? 0.0`. Demanding it breaks the next rebuild, and a
  rebuild is the worst moment to find out. Never insert a parameter *before* the
  `$eventId`/`$occurredAt` tail either — the deserialiser passes those positionally, and
  PHPStan is what catches it.
- **A new repository has to name its projection.** `EventSourcedRepository` is abstract on
  `projectionName()`, and the write path records that position as it commits. Return the
  matching projector's `NAME` constant rather than a string, so the two cannot drift.
- **A rebuild is not a command.** `POST /admin/projections/{name}/rebuild` is deliberately
  *not* marked `'command' => true` — it changes no domain state and does not belong in the
  command history.
- **A new tipp year is `planned` and accepts no ticket.** It first has to go to `running`
  through `PUT /admin/tipp-years/{id}/status` (B-18). If you are wondering why a walkthrough
  fails at B-12: that is the reason, not a bug in the handler.
- **Creating a `Participant` still has no route.** Self-registration is E1-01; for a
  walkthrough the participant has to be prepared.
- **For a tipp year every status transition is allowed, backwards ones included.** That is
  deliberate: a year closed too early has to be reopenable, and that correction belongs in
  the event history rather than in a manual `UPDATE`. The one rule that remains spans
  aggregates and therefore does not live in the model: **at most one year is `running`.**
  `ChangeTippYearStatusHandler` checks it for the error message, but the decision is made by
  the unique key on `tipp_year.running_marker` — a generated column that is `NULL` outside
  `running`, because equal `NULL`s do not collide in a unique key.
- **Volumes survive every schema and realm change.** The same pitfall twice, hit twice on
  2026-07-29:

  | File | Is read | Volume |
  |---|---|---|
  | `database/schema.sql` | only with an **empty** data directory (`docker-entrypoint-initdb.d`) | `db_data` |
  | `keycloak/realm-export.json` | only when the realm **does not exist yet** (`--import-realm`) | `keycloak_db_data` |

  A change to either file has **no effect at all** after `restart`, `up -d` or `down`
  without `-v`. The stack then keeps running with the state from back then, without an error
  appearing anywhere — after the change of course the old sports-betting schema sat in the
  database for months, and every lotto query ended in a `500`.
  This can only be checked on the running instance:

  ```bash
  docker-compose exec -T db mariadb -uroot -psecret -N \
    -e "SELECT table_name FROM information_schema.tables WHERE table_schema='betting_game';"
  ```

  Reloading works without deleting the volume — `schema.sql` starts with
  `DROP TABLE IF EXISTS` for every table. If a foreign schema is still in the database, the
  order of the `DROP`s does not bite, because it is laid out for the *new* foreign-key
  graph; in that case switch the check off for the session:

  ```bash
  (echo "SET FOREIGN_KEY_CHECKS=0;"; cat database/schema.sql) \
    | docker-compose exec -T db mariadb -uroot -psecret betting_game
  ```

- **Do not touch:** `vendor/`, `coverage/`, `.phpunit.cache/`, `var/` — generated.
- **Duplicate configuration files** in `docker/` (`Caddyfile.minimal`,
  `Caddyfile.alternative`, `php-fpm.conf.minimal`) are remnants of troubleshooting; only the
  ones mounted from `docker-compose.yml` are active.

---

## 10. Operations

| Story | Endpoint |
|---|---|
| OPS-01 processing state of a command | `GET /commands/{commandId}` |
| OPS-02 repeatability | The `Idempotency-Key` header on all command routes |
| OPS-03 event history of an aggregate | `GET /admin/audit/{type}/{id}` |
| OPS-04 monitor / rebuild projections | `GET /admin/projections`, `POST /admin/projections/{name}/rebuild` |

A retry with a known `Idempotency-Key` returns the stored response with its original status
code and the header `Idempotent-Replay: true`.
`GET /commands/{commandId}` is not admin-protected: whoever issued the command may look, and
nobody can guess the UUID.
