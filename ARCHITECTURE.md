# Architecture

How the application is built, and why. The domain is in
[USER_STORIES.md](USER_STORIES.md), the working guide in [AGENTS.md](AGENTS.md).

State: expansion stage base, fully implemented — 18 domain stories, 4 operations stories,
23 routes.

---

## 1. Onion architecture

```
┌──────────────────────────────────────────────┐
│ Presentation   controllers, router, kernel   │ → depends on Application
├──────────────────────────────────────────────┤
│ Application    commands, queries, projection │ → depends on Domain
├──────────────────────────────────────────────┤
│ Domain         aggregates, VOs, events       │ → depends on nothing
├──────────────────────────────────────────────┤
│ Infrastructure PDO, event store, auth, cache │ → implements domain interfaces
└──────────────────────────────────────────────┘
```

`src/Domain/` imports nothing outside `BettingGame\Domain` — no PDO, no HTTP, no PSR
packages. Anyone writing a `use BettingGame\Infrastructure\…` in there has broken the
architecture. The practical benefit is not exchangeability on a brochure, but that the
domain rules are testable without a database: `tests/Unit/Domain/` runs without anything.

The only place that knows all the layers is
[Container.php](src/Infrastructure/DI/Container.php).

---

## 2. Request flow

```
public/index.php          globals → request, build the container, send the response
  └─ Kernel::handle()     src/Presentation/Http/Kernel.php
       ├─ Router          FastRoute, routing table in Presentation/Router/Router.php
       ├─ AuthMiddleware  except where 'public' => true
       ├─ Authorization   where 'role' => 'admin'
       ├─ command_log     where 'command' => true
       ├─ Controller      Input::* validates, command/query DTO, handler
       └─ ErrorMapper     domain exception → HTTP status
```

The whole sequence lives in the [Kernel](src/Presentation/Http/Kernel.php), not in
`index.php` and not in the controllers. That is why
[HttpTestCase](tests/Integration/Http/HttpTestCase.php) can drive the complete chain without
a web server. New cross-cutting logic belongs exactly there.

### Route flags

| Flag | Effect |
|---|---|
| `'public' => true` | No authentication. **Only** `/health` |
| `'role' => 'admin'` | The kernel enforces the admin role before the controller |
| `'command' => true` | Runs under the command log and the idempotency key |
| (nothing) | Authenticated; the ownership check is the controller's job |

A route is **authenticated by default**. The other way round, a forgotten flag would
silently make it public. Path parameters are constrained with `{id:\d+}` so that a mistyped
URL gives `404` instead of a `400` from the depths of a handler.

### Access protection

`Authorization::requireSelf()` compares the `participantId` from the path with the token's
`participant_id` claim. Identity comes **from the token**, otherwise the check would only
confirm itself. It is deliberately strict enough that an admin does not get through either —
they have their own endpoints, otherwise the participant routes would be a second, quieter
admin API.

The check runs **before** the query. Otherwise a `404` would already reveal that nothing
exists for someone else's participant.

---

## 3. Event sourcing and CQRS

### Write path

```
command DTO
  → handler                loads the aggregate through a repository interface
    → domain method        enforces the rule, recordEvent()
      → repository         transactionally(): events + projection, one COMMIT
        → CommandResult    202 accepted
```

Aggregates record their events through the trait
[RecordsEvents](src/Domain/Model/RecordsEvents.php). Saving goes through
[EventSourcedRepository](src/Infrastructure/Persistence/EventSourcedRepository.php), the
shared `abstract` base of all repositories — one of the few inheritances in the project.

### Read path

Query handlers read the projection tables directly. No events, no reconstruction, no joins
across the event store. That is why a read query stays a simple `SELECT`, even when the
aggregate behind it has 200 events.

### The two paths to the same rows

Repositories write their projection **synchronously** while saving — a `load()` right
afterwards has to see the row. The seven projectors in `src/Infrastructure/Projection/`
are the *second* path: they replay the event log, triggered through
`POST /admin/projections/{name}/rebuild`.

Two paths to the same tables drift apart when nobody looks. That is why
[ProjectionRebuildTest](tests/Integration/Application/ProjectionRebuildTest.php) plays a
whole tipp year through the command handlers, photographs all 13 read-model tables, rebuilds
from the event store and compares row by row. The only deviation allowed:
`ticket_row_match.calculated_at` — that records *when* the calculation happened.

**Whoever changes a projection changes both sides.**

### Optimistic locking

The event store writes with an expected stream version. If it does not match, it throws
`ConcurrencyException` → `409`. The loser retries; thanks to the idempotency key that is
harmless.

IDs are handed out by `nextId()` as `MAX(id) + 1`. Under concurrency the unique key on the
target table decides, not a check in code.

### Honest about asynchrony

The OpenAPI specification describes commands as asynchronous (`202 accepted`). This
implementation writes synchronously: by the time the caller holds the `202` the command is
already `completed` and `projectionsUpToDate` is always `true`. The status endpoint remains
useful nonetheless — that is where a retry looks up what the first attempt produced.

---

## 4. Class map

155 files under `src/`, one class per file, PSR-4 mapping 1:1 onto the namespace structure.
The namespace root is historically `BettingGame\`, despite the lotto domain.

### Domain (`src/Domain/`)

| Directory | Contents |
|---|---|
| `Model/` | 7 aggregates — `TippYear`, `BetPeriod`, `BetRow`, `Ticket`, `Draw`, `Fee`, `Participant` — plus the trait `RecordsEvents` |
| `ValueObject/` | `LottoNumbers`, `Superzahl`, `DateRange`, `EvenSplit`, `WinningClass`, `TippYearStatus`, `ParticipantId`, `Email`, `DisplayName` |
| `Event/` | `DomainEvent` + 14 concrete events |
| `Repository/` | 10 interfaces + `RecordedEvent` |
| `Service/` | `WinningsDistribution` |
| `Exception/` | 7 classes under `DomainException` |

**Value objects are immutable and validate in the constructor.** `LottoNumbers` takes
exactly six distinct numbers from 1–49 and stores them ascending; `Superzahl` 0–9. An
aggregate therefore cannot even get into an invalid state.

`EvenSplit` divides monetary amounts **in whole cents** and puts the remainder onto the
first share. Dividing in floating point and rounding per share creates or destroys money:
€100.00 across three gives three times €33.33 and one cent disappears. This affects the
yearly distribution (B-13) and the split of a ticket's winnings across the rows (B-09).

`WinningsDistribution` lives in the **domain service**, because two callers need the same
calculation: the command handler when recording the winnings, and the `DrawProjector` on a
rebuild. `ticket_row_match` appears in no event — the projector has to recompute the rows,
and with the same logic.

### Exception hierarchy

```
DomainException
├── InvalidArgumentException          → 400
├── EntityNotFoundException           → 404
├── ConcurrencyException              → 409
├── BusinessRuleViolationException    → 409
│   └── DuplicateEntryException       → 409
└── UnauthorizedAccessException       → 403
```

`DuplicateEntryException` exists because rules such as "one row per participant and period"
live in the schema, not in code. Without it the application layer would have to catch a
`PDOException` and read SQLSTATE to recognise that a *domain rule* said no.

### Application (`src/Application/`)

| Directory | Contents |
|---|---|
| `Command/` | 9 commands + handlers, `CommandResult` |
| `Query/` | 10 queries + handlers, `QueryResult` |
| `Projection/` | `ProjectionManager`, `Projector`, `ProjectionStatus` |

Handlers know nothing about HTTP: they take a DTO, work through repository interfaces and
throw domain exceptions.

| Story | Command handler | Query handler |
|---|---|---|
| B-01 … B-04 | — | `GetBetRow`, `GetMemberships`, `GetParticipantFees`, `GetPayoutShare` |
| B-05 | — | `GetDraws` |
| B-06 | `AssignBetRow` | — |
| B-07 | `RecordFeePayment` | `GetFees` |
| B-08 / B-09 | `RecordDraw`, `RecordDrawWinnings` | — |
| B-10 / B-14 | `CreateTippYear`, `CreateBetPeriod` | `GetTippYears`, `GetBetPeriods` |
| B-11 … B-13 | `AddMember`, `SubmitTicket`, `DistributePayout` | — |
| OPS-01 / OPS-03 | — | `GetCommandStatus`, `GetAuditTrail` |

### Infrastructure (`src/Infrastructure/`)

| Directory | Contents |
|---|---|
| `Auth/` | `TokenVerifier`, `JwkSet`, `KeycloakKeys`, `StaticKeys`, `KeycloakService`, `AuthMiddleware`, `CurlFetcher` |
| `Cache/` | `FileCache`, `RedisCache` (PSR-16) |
| `Config/` | `Config` — typed access to the config array |
| `DI/` | `Container` (PHP-DI), `PsrContainer` (PSR-11) |
| `EventStore/` | `PdoEventStore` |
| `Persistence/` | `Db` + 9 repositories, `EventSourcedRepository` as the base |
| `Projection/` | 7 projectors, one per read model |
| `Logging/` | `LoggerFactory` (Monolog, PSR-3) |

Which aggregate writes which projections:

| Aggregate | Repository | Projections |
|---|---|---|
| `TippYear` | `TippYearRepository` | `tipp_year`, `membership`, `payout`, `payout_share` |
| `BetPeriod` | `BetPeriodRepository` | `bet_period` |
| `BetRow` | `BetRowRepository` | `bet_row` |
| `Ticket` | `TicketRepository` | `ticket`, `ticket_row` |
| `Draw` | `DrawRepository` | `draw`, `ticket_draw_result`, `ticket_row_match` |
| `Fee` | `FeeRepository` | `fee` |
| `Participant` | `ParticipantRepository` | `participant` |

**Two decisions that are easy to miss while reading:**

A new aggregate is written with a plain `INSERT`, a loaded one with an `UPDATE` — no
`ON DUPLICATE KEY UPDATE`. That would hit *every* unique key and, on a second bet row for
the same period, would silently overwrite the existing one instead of raising the `409`
that B-06 requires.

Appending and writing the projection run in **one** transaction. Otherwise a row rejected by
the unique key would leave a `bet_row.assigned` event in the store that describes no row.

### Presentation (`src/Presentation/`)

| Directory | Contents |
|---|---|
| `Controller/` | 9 controllers |
| `Http/` | `Kernel`, `Request`, `JsonResponse`, `Input`, `Authorization`, `ErrorMapper`, `InvalidInputException` |
| `Router/` | `Router` (FastRoute) |

### Support (`src/Support/`)

`Row` — typed access to a database row. Together with `Http\Input` (for request bodies) it
is the reason PHPStan level 10 passes without casts: `mixed` from external sources is
checked in exactly two places instead of guessed everywhere.

---

## 5. Operations

| Story | Implementation |
|---|---|
| OPS-01 | `GET /commands/{commandId}` — processing state out of `command_log` |
| OPS-02 | The `Idempotency-Key` header on all command routes |
| OPS-03 | `GET /admin/audit/{type}/{id}` — event history of an aggregate |
| OPS-04 | `GET /admin/projections`, `POST /admin/projections/{name}/rebuild` |

**The idempotency key is claimed *before* the command runs.** Checking first and executing
afterwards would leave a window in which two parallel retries both get through — exactly the
double booking the key exists to prevent. The unique key on the column decides the race. A
retry returns the stored response with its original status code and the header
`Idempotent-Replay: true`.

The response's `commandId` is the primary key in `command_log`: the handler creates one of
its own, and the kernel overwrites it with the logged one so that `GET /commands/{id}`
actually finds it.

**A rebuild is not a command.** `POST /admin/projections/{name}/rebuild` is deliberately
*not* marked `'command' => true` — it changes no domain state and does not belong in the
command history.

**A rebuild reaches downwards.** The read models hang together through
`ON DELETE CASCADE`: emptying `participant` also empties `membership`, `bet_row` and `fee`.
A rebuild therefore rebuilds the dependent projections along with it — otherwise they would
stay empty and nobody would notice. The response lists everything that was actually rebuilt.

---

## 6. Authentication

Identity comes from the token — so every rule above hangs on the token really coming from
Keycloak. Verification happens in this order:

| Check | Against what |
|---|---|
| `alg` against an allowlist | `alg: none`; HS256 with the public key as the "secret" |
| Signature against the public key from the JWKS | forged and subsequently altered tokens |
| `exp`, `nbf`, `iat` (with leeway) | expired tokens, clock drift |
| `iss` verbatim | a validly signed token from the wrong realm |
| `aud`, where configured | a token for a different client |

The allowlist **can only contain asymmetric algorithms** — the constructor rejects anything
else. Both classic forgeries therefore fail at the same place, and an `HS256` in the
configuration shows up at startup rather than on the request that would have been forged
with it.

The key always comes **from the key set, never from the token**. An unknown `kid` triggers
exactly one throttled refetch. An unreachable Keycloak is **503, not 401**. A last-known key
set survives an outage — signing keys rotate monthly, tokens expire within an hour.

Details and configuration: [KEYCLOAK.md](KEYCLOAK.md).

---

## 7. Tests

| Suite | Files | Test methods | Prerequisite |
|---|---|---|---|
| `tests/Unit` | 19 | 181 | none |
| `tests/Integration` | 16 | 157 | MariaDB |

- Integration tests **skip themselves** when no database is reachable
  (`IntegrationTestCase::setUpBeforeClass()`). "All tests green" without a running database
  therefore does not mean persistence was checked.
- Repositories are **not** tested against a mocked PDO. They are almost entirely SQL —
  unique keys, joins, upserts; a mock would only confirm that we wrote the strings we wrote.
- Handlers, too, run with **real** repositories: which rows a query returns, which unique key
  bites and whether a projection ends up consistent can only be answered by a database.
- `HttpTestCase` / `ApplicationTestCase` drive the full chain kernel → controller →
  handler → repository.
- `tests/Support/SigningKey.php` creates tokens for the auth tests — no real Keycloak needed.

```bash
make test-db-start && make test-integration && make test-db-stop
```

---

## 8. Code quality

- **PHPStan level 10** on `src`, `treatPhpDocTypesAsCertain: false`, clean.
  `array<string, mixed>` from external sources is checked explicitly (`is_int`, `is_string`,
  `assert(… instanceof …)`), never blindly cast. No `@phpstan-ignore` without need.
- **PSR-12**, checked through `phpcs` on `src tests public config`.
- `declare(strict_types=1);` in every file under `src/` and `tests/`.
- `final` by default; `EventSourcedRepository` is one of the few `abstract` bases.
- Comments explain **why**, not what. Class docblocks name the story ID.

---

## 9. Open points

**Domain**

- **The HTTP surface of the base version is complete.** The tipp year's lifecycle has run
  through `PUT /admin/tipp-years/{id}/status` since B-18, participants come into being
  through `POST /admin/participants` since B-21. A walkthrough therefore no longer needs a
  hand-written `INSERT`. **Self**-registration remains E1-01.
- E1 (self-service) and E2 (sports betting) are specified but not implemented.
  The E2 artefacts are ready as [betting_game_api_e2_sports.yaml](betting_game_api_e2_sports.yaml)
  and [database/schema-e2-sports.sql](database/schema-e2-sports.sql).
- The [frontend/](frontend/) serves the base version completely and has automated tests:
  Vitest for composables, stores and the router guard, Playwright for the pass against the
  running stack — see [FRONTEND.md](FRONTEND.md).

**Technical**

- **Cache:** PSR-16 is implemented and in production is used only by `KeycloakKeys`
  (the JWKS cache). Read models are not cached.
- **Logging:** PSR-3 is wired up, but only `AuthMiddleware` writes. Command and query
  handlers do not log.
- **`event_publisher`** exists as an outbox table; there is no publisher draining it.
  Events do not leave the system.
- **`snapshot`** exists; no snapshot is written or read. At the current stream lengths that
  is not a problem.
- The `user` table dates from before Keycloak and is no longer written by any projector.
  Participants created through B-21 therefore leave `participant.user_id` as `NULL` — the
  column was nullable in the schema all along ("guest participants have no account"), only
  the aggregate demanded a value until then.
- No rate limiting, no metrics, no tracing.

**Deliberately not done**

- No framework. The cost would be autoload and bootstrap overhead for building blocks that
  here consist of one class each.
- No move to PSR-7/PSR-15. `Request`/`JsonResponse` are small and do what they should; the
  switch would only make sense as a pair, see [PSR.md](PSR.md).
- No measured performance figures. There is no benchmark setup in the repository, and
  estimated numbers in an architecture document are worse than none.
