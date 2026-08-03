# Quick start

From an empty repository to a tipp year played through. Domain background:
[USER_STORIES.md](USER_STORIES.md), architecture: [ARCHITECTURE.md](ARCHITECTURE.md).

## 1. Start the stack

```bash
docker-compose up -d
docker-compose exec php composer install
curl http://localhost:8080/health          # {"status":"healthy","timestamp":"..."}
```

| Service | URL | Access |
|---|---|---|
| API (Caddy) | http://localhost:8080 | bearer token |
| PHPMyAdmin | http://localhost:8081 | root / secret |
| Keycloak | http://localhost:8090 | admin console `/admin`, admin / admin |
| MariaDB | localhost:3306 | root / secret, DB `betting_game` |

On its first start Keycloak needs 30–60 seconds for the realm import:
`docker-compose logs -f keycloak`, wait for `Keycloak 26.7.x started`.

The schema is loaded automatically from [database/schema.sql](database/schema.sql) on the
database's first start. To load it again: `make db-reset`.

> This walkthrough deliberately goes through `curl`. The same steps also exist as a user
> interface in the `frontend` container on port 3000 ([FRONTEND.md](FRONTEND.md)); if you
> do not need it, stop it: `docker-compose stop frontend`.

## 2. Get a token

The demo users are listed in [keycloak/realm-export.json](keycloak/realm-export.json):

| Username | Password | Roles | `participant_id` |
|---|---|---|---|
| `admin` | `admin123` | user, admin | 1 |
| `testuser` | `test123` | user | 2 |
| `john.doe` | `password` | user | 3 |

```bash
token() {
  curl -s -X POST http://localhost:8090/realms/betting-game/protocol/openid-connect/token \
    -d client_id=betting-game-frontend -d grant_type=password \
    -d "username=$1" -d "password=$2" | jq -r .access_token
}

ADMIN=$(token admin admin123)
USER=$(token testuser test123)
```

Without a valid token every route except `/health` answers `401`. If Keycloak is
unreachable the answer is **503** — a key problem is not an invalid token.

## 3. Create participants (B-21)

```bash
api() { curl -s -X "$1" "http://localhost:8080$2" \
  -H "Authorization: Bearer $ADMIN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" ${3:+-d "$3"}; }

api POST /admin/participants '{"displayName":"Admin"}'
api POST /admin/participants '{"displayName":"Test User"}'

curl -s http://localhost:8080/admin/participants -H "Authorization: Bearer $ADMIN"
```

The IDs handed out come back as `resourceId` in each response. **For someone to see their
own data, that same ID has to be stored in the realm as the user's `participant_id`
attribute** — the demo users from step 2 carry 1, 2 and 3, which is why the order above
matches `admin` and `testuser`.

**No** user account is linked: identity comes from the token, and the `user` table dates
from before that. Self-registration is E1-01.

> Until B-21 there was a hand-written `INSERT` here, with the note that such rows appear in
> no event and vanish on the next `POST /admin/projections/participant_read_model/rebuild`.
> Participants created through the command survive a rebuild.
> For the E2E tests, which would rather not use an admin route, the old way is still
> available as [`database/seed-demo-participants.sql`](database/seed-demo-participants.sql).

## 4. Set up a tipp year

Every command carries an `Idempotency-Key`, every one answers with `202` and a
`resourceId`.

From here on use the `api()` helper from step 3.

**B-10 — create the tipp year**

```bash
api POST /admin/tipp-years \
  '{"name":"Tippjahr 2026","startDate":"2026-01-01","endDate":"2026-12-31","ticketCostPerRow":1.20,"processingFeeSingleWeek":0.60,"processingFeeMultiWeek":1.00}'
```

```json
{"commandId":"8f14e45f-…","status":"accepted","resourceId":1,"timestamp":"…"}
```

**B-14 — define the bet periods.** They have to lie inside the tipp year and must not
overlap. A single period spanning the whole year yields "one row per year".

```bash
api POST /admin/tipp-years/1/bet-periods '{"name":"Q1 2026","startDate":"2026-01-01","endDate":"2026-03-31"}'
api POST /admin/tipp-years/1/bet-periods '{"name":"Q2 2026","startDate":"2026-04-01","endDate":"2026-06-30"}'
```

**B-11 — add the participants**

```bash
api POST /admin/tipp-years/1/members '{"participantId":1}'
api POST /admin/tipp-years/1/members '{"participantId":2}'
```

**B-06 — assign the bet rows.** Six distinct numbers from 1–49; stored ascending.

```bash
api PUT /admin/participants/1/bet-row '{"betPeriodId":1,"numbers":[3,12,19,27,33,45]}'
api PUT /admin/participants/2/bet-row '{"betPeriodId":1,"numbers":[7,8,9,10,11,12]}'
```

A second attempt for the same period is rejected with `409` — enforced by the unique key,
not by a check in code. A correction within the running period requires an explicit
reason:

```bash
api PUT /admin/participants/2/bet-row \
  '{"betPeriodId":1,"numbers":[1,2,3,4,5,6],"replaceReason":"wrong row transcribed"}'
```

## 5. Start the tipp year (B-18)

A ticket is only accepted while the tipp year is `running`:

```bash
api PUT /admin/tipp-years/1/status '{"status":"running"}'
```

**At most one tipp year runs at a time** — for a second one the same call answers `409` and
names the year that blocks it. This is enforced by the unique key
`tipp_year.running_marker`, not by the check in the handler.

## 6. Ticket, draws, winnings

**B-12 — submit the ticket.** Handed in on `periodStart` for a `durationWeeks`-long
Laufzeit, playing `drawDays` (`wednesday`, `saturday` or `both`). The period's end and the
number of draws are derived from those and cannot be sent. Bundles the rows of all
participants whose period contains `periodStart`, copies them as a snapshot into
`ticket_row` and creates one `Fee` per participant.
`total_cost = row_count × draw_count × ticketCostPerRow + processingFee`, where the
Bearbeitungsentgelt comes from the tipp year's price list and is picked by the length of
this ticket.

```bash
api POST /admin/tipp-years/1/tickets \
  '{"periodStart":"2026-01-01","durationWeeks":4,"drawDays":"both","superzahl":7,"lotteryReference":"LOT-2026-01"}'
```

Four weeks from 1 January run through 28 January and play eight draws — two a week,
holidays included.

The snapshot is the point: a later correction to a `BetRow` does not change tickets that
have already been submitted.

**B-08 — record a draw.** A duplicate draw date → `409`. Bonus number 0–9.

```bash
api POST /admin/draws '{"tippYearId":1,"drawDate":"2026-01-07","numbers":[3,12,19,27,40,41],"superzahl":7}'
```

**B-22** runs with it: the rows of the covering ticket are evaluated straight away and land
in `ticket_row_match` with their winning class and no amount. The response says how many —
`"message": "Draw recorded, 3 rows of ticket 1 evaluated"`.

**B-09 — record the winnings.** The amount is the winnings of the *whole* ticket. The
system computes the hits per row from the winning numbers and the row snapshots; the split
runs in whole cents through `EvenSplit`.

```bash
api PUT /admin/draws/1/winnings '{"totalAmount":123.45}'
```

**B-23 — or class by class.** Whoever reads the statement per winning class enters what
**one** row of that class was paid and leaves the total out — it follows from the rows:

```bash
api POST /admin/draws '{"tippYearId":1,"drawDate":"2026-01-10","numbers":[3,12,19,33,44,45],"superzahl":7}'
api PUT /admin/draws/2/winnings \
  '{"winningClasses":[{"winningClass":5,"amountPerRow":150.00},{"winningClass":8,"amountPerRow":12.50}]}'
```

`total = Σ amountPerRow × rows of the ticket in that class`. Which rows are in which class
comes from the row snapshots, so a class nobody reached contributes nothing however large
its amount — and every class entered is recorded with its row count, so the booking still
reads like the statement it was typed from.

Exactly one of the two has to be there: neither is `400`, and so is both — as is a class
listed twice.

**B-07 — record a payment.** `GET /admin/fees` returns the fee IDs.

```bash
curl -s http://localhost:8080/admin/fees -H "Authorization: Bearer $ADMIN"

api PUT /admin/fees/1/payment \
  '{"paymentStatus":"paid","paidAt":"2026-01-20 10:00:00","paymentMethod":"bank_transfer"}'
```

## 7. Yearly distribution

Distributing works only out of the status `closed`, and only once:

```bash
api PUT /admin/tipp-years/1/status '{"status":"closed"}'
```

**B-13 — record the distribution.** `confirm` missing or `false` → `409`: a distribution
cannot be undone and is therefore never accepted, only confirmed.

```bash
api POST /admin/tipp-years/1/payout '{"confirm":true,"note":"Year-end 2026"}'
```

It is distributed **evenly across all participants of the tipp year**, regardless of how
many periods anyone paid for. The rounding difference goes onto the first share.

## 8. The participant's view

With `testuser`'s token (`participant_id: 2`):

```bash
curl -s http://localhost:8080/participants/2/bet-row       -H "Authorization: Bearer $USER"
curl -s http://localhost:8080/participants/2/memberships   -H "Authorization: Bearer $USER"
curl -s http://localhost:8080/participants/2/fees          -H "Authorization: Bearer $USER"
curl -s http://localhost:8080/participants/2/payout-share  -H "Authorization: Bearer $USER"
curl -s http://localhost:8080/tipp-years/1/draws           -H "Authorization: Bearer $USER"
```

Access to someone else's data is rejected with `403` — with the admin token too:

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  http://localhost:8080/participants/1/fees -H "Authorization: Bearer $USER"   # 403
```

Identity comes from the token, never from the path. The admin has their own endpoints —
otherwise the participant routes would be a second, quieter admin API.

## 9. Look at operations

```bash
# OPS-01: what became of a command?
curl -s http://localhost:8080/commands/8f14e45f-… -H "Authorization: Bearer $ADMIN"

# OPS-03: event history of an aggregate
curl -s http://localhost:8080/admin/audit/tipp_year/1 -H "Authorization: Bearer $ADMIN"

# OPS-04: monitor and rebuild projections
curl -s http://localhost:8080/admin/projections -H "Authorization: Bearer $ADMIN"
curl -s -X POST http://localhost:8080/admin/projections/tipp_year_read_model/rebuild \
  -H "Authorization: Bearer $ADMIN"
```

**Try OPS-02 out:** send the same command twice with the same `Idempotency-Key`. The second
call executes nothing and returns the stored response with its original status code and the
header `Idempotent-Replay: true`.

A rebuild reaches downwards: emptying `participant` also empties `membership`, `bet_row`
and `fee` through `ON DELETE CASCADE`, so the dependent projections are rebuilt along with
it. The response lists everything that was actually rebuilt.

## 10. Tests and checks

Tests run in a **dedicated** environment with its own database:

```bash
make test-db-start        # MariaDB 11.4 on port 3307, schema loaded
make test-docker          # phpunit --testdox
make phpstan-docker
make test-db-stop
```

> **Do not test in the `php` container.** There `DB_DATABASE` is the development database,
> and the integration suite truncates every table before each test — a run would clear away
> the tipp year from this walkthrough. `IntegrationTestCase` rejects any database whose name
> does not end in `_test`, and skips itself with a note.

Read-only checks are harmless in the dev container:

```bash
docker-compose exec php vendor/bin/phpstan analyse
docker-compose exec php vendor/bin/phpcs --standard=PSR12 src tests public config
```

The integration tests **skip themselves** when no database is reachable. Green output
without a running database therefore proves nothing about persistence — watch the line
`Tests: N … Skipped: N`, not the exit code.

The frontend has its own suites (Vitest, Playwright) — see [FRONTEND.md](FRONTEND.md).

## Common problems

**`401` on every route** — token expired (lifetime 60 minutes) or issued for the wrong
realm. Fetch a new one, see step 2.

**`503` instead of `401`** — the API cannot reach Keycloak.

```bash
docker-compose exec php curl -s http://keycloak:8080/realms/betting-game | head -c 100
```

The backend talks to Keycloak under the internal name `keycloak:8080`, the frontend under
`localhost:8090`.

**`409` on the ticket** — the tipp year is not `running`, see step 5.

**`409` on a bet row** — one already exists for this period. Replace it with
`replaceReason` or pick the next period.

**"Connection refused" to the database**

```bash
docker-compose ps
docker-compose logs db
```

**Reload the schema** — `make db-reset` (deletes nothing, replays `schema.sql`; for a
genuinely empty state use `docker-compose down -v`).

## Where to go next

- [USER_STORIES.md](USER_STORIES.md) — what the system does in domain terms, story by story
- [ARCHITECTURE.md](ARCHITECTURE.md) — layers, event sourcing, open points
- [KEYCLOAK.md](KEYCLOAK.md) — users, roles, token verification
- [DOCKER.md](DOCKER.md) — stack, tuning, troubleshooting
- [betting_game_api.yaml](betting_game_api.yaml) — the complete API contract
