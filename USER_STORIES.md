# User stories

A working document: which domain requirements the system covers, through which endpoint, on
which tables — and how much of that is already implemented.

Derived from [betting_game_er_extended.mermaid](betting_game_er_extended.mermaid).
State: 2026-07-29.

## The domain

Administration of a **lottery syndicate playing Lotto 6 aus 49**.

Each participant has **exactly one bet row per bet period**, of six numbers. It stands until
revoked and travels automatically onto every ticket — the participant does not bet per draw,
but once per period.

**How long a period runs is up to the administrator.** A period spanning the whole tipp year
yields "one row per year", twelve monthly periods allow a monthly change. Periods of one
tipp year must not overlap — otherwise two rows of the same participant would be valid on
the same day and the ticket would not know which one to print.

At the start of each month the syndicate submits all active rows to the lottery company as
**one shared ticket**. The cost of that ticket is split across the participants and is due
during that month.

Winnings accrue **per draw for the ticket as a whole**, are collected over the tipp year and
distributed **evenly across all participants** at the end of the year — regardless of how
many periods anyone paid for.

Joining and leaving are regularly possible **only at the turn of the year**, in the course of
the yearly distribution. The bet row changes with the period.

### Decisions taken

| Question | Decision |
|---|---|
| Tipp year | A freely definable period, not a calendar year — `TippYear.start_date`/`end_date` |
| Bet period | A freely chosen period within the tipp year — `BetPeriod.start_date`/`end_date`, non-overlapping |
| Distribution of winnings | Evenly across all participants of the tipp year |
| Changing a bet row | One row per period (enforced through the UK `(participant_id, bet_period_id)`) |
| Bonus number | Per ticket, from the lot number — a bet row is only the six numbers |

The period length is therefore a **configuration, not an assumption in code**. The edge case
"one period = the whole tipp year" reproduces exactly the behaviour originally described.

## Expansion stages

| Stage | Contents |
|---|---|
| **Base** | The lotto syndicate. Participants read only, the admin maintains rows, payments, draws and winnings. |
| **E1 — self-service** | Self-registration, profile, choosing one's own row, joining/leaving, notifications |
| **E2 — sports betting** | A betting game on sports results: events with a betting deadline, one bet per event, points, leaderboard |

## Roles

| Role | Keycloak | Description |
|---|---|---|
| **Participant** | `user` | In the base version sees exclusively their own data — no write access |
| **Administrator** | `admin` | Maintains the tipp year, rows, tickets, draws, winnings and payments |
| **Operator** | `admin` | The operations view onto event sourcing |

## Status legend

| Symbol | Meaning |
|---|---|
| 🟢 | Route + controller + handler + persistence in place, tested over HTTP |
| 🟠 | Route reachable, the handler still a stub |
| 🔵 | Specified, not implemented yet |
| ♻️ | Existing code directly reusable (see the migration table) |

---

# Base version

## Participant — read only

| ID | Story | Endpoint | Data model | Status |
|---|---|---|---|---|
| **B-01** | As a **participant** I want to see my bet row, so that I know which numbers I am playing in the running period. | `GET /participants/{id}/bet-row` | **BetRow** ⋈ **BetPeriod** ⋈ **TippYear** | 🟢 ♻️ |
| **B-02** | As a **participant** I want to see my memberships in the running tipp year, so that I know which tickets my row appeared on. | `GET /participants/{id}/memberships` | **Membership** ⋈ **TippYear**, **TicketRow** ⋈ **Ticket** | 🟢 ♻️ |
| **B-03** | As a **participant** I want to see my payments, so that I know which fees are outstanding. | `GET /participants/{id}/fees` | **Fee** ⋈ **Ticket** | 🟢 ♻️ |
| **B-04** | As a **participant** I want to see my proportional winnings for the tipp year, so that I know what will be distributed. | `GET /participants/{id}/payout-share` | **PayoutShare** ⋈ **Payout** ⋈ **TippYear** | 🟢 |
| **B-05** | As a **participant** I want to see the ticket's winnings per draw, so that I can follow the course of the tipp year. | `GET /tipp-years/{id}/draws` | **Draw** ⋈ **TicketDrawResult** | 🟢 |
| **B-24** | As a **participant** I want to see all rows of the active ticket for a draw, with the winning ones highlighted, so that I can tell at a glance what the syndicate achieved. | `GET /tipp-years/{id}/draws` | **TicketRow** ⋈ **TicketRowMatch** ⋈ **Participant** | 🟢 |
| **B-26** | As a **participant** I want to see which slip took part in a draw, by its Losnummer and the Superzahl that follows from it, so that I can check the evaluation against the ticket in my hand. | `GET /tipp-years/{id}/draws` | `Ticket.lottery_reference`, `.superzahl` | 🟢 |

**Acceptance criteria:**

- B-01: `404` for as long as no row is assigned to the participant for the running period
- B-02: contains, per ticket, whether one's own row appeared on it — after joining mid-year it is missing from earlier tickets
- B-04: `200` with `amount: null` for as long as the yearly distribution has not been recorded — the story only carries substance after B-13
- B-05: shows the winnings of the **whole** ticket, not one's own share. The share only comes into being with the distribution
- B-24: **every** row of the covering ticket, not only the winning ones — a row that hit nothing is a result too, and a list of only the winners would look like a ticket that never carried the others
- B-24: the numbers are the `TicketRow` snapshot, so a bet row corrected afterwards does not rewrite what took part in the draw
- B-24: `draw.ticket` is the ticket whose period contains the draw date and appears as soon as that ticket exists — before B-24 it appeared only with the winnings. `totalAmount` is therefore `null` until they are recorded, which is not the same as `0.00`
- B-24: every participant sees every row of the ticket, with the name of whoever plays it. That is a **deliberate widening** of what a participant sees: B-16 guards the per-participant endpoints, where the path carries a `participantId`, and this one carries none. The rows of one ticket are the syndicate's shared business — it is handed in as one slip and everyone pays a share of it — but if that is not wanted, this is the place to say so
- B-26: the ticket's Superzahl is **not** the drawn one and is shown as its own fact. It is the last digit of the Losnummer, it applies to every row of the slip, and it is what decides the classes "+ Superzahl". Where it is missing, no row can reach one of them — which the view says rather than leaving the reader to work out
- B-26: the Losnummer is what makes the choice of ticket checkable. Ticket periods may overlap (only two *starts* on the same day are refused), so the API picks the one handed in last; naming it by the number on the slip is what lets a reader see which one that was

## Administrator

| ID | Story | Endpoint | Data model | Status |
|---|---|---|---|---|
| **B-06** | As an **administrator** I want to assign a participant a bet row for a period. | `PUT /admin/participants/{id}/bet-row` | **BetRow** | 🟢 |
| **B-07** | As an **administrator** I want to set a participant's payment status for a period, so that the fee situation is correct. | `PUT /admin/fees/{feeId}/payment` | `Fee.payment_status`, `.paid_at`, `.booked_by` | 🟢 ♻️ |
| **B-08** | As an **administrator** I want to record a draw with its numbers and bonus number. | `POST /admin/draws` | **Draw** | 🟢 |
| **B-09** | As an **administrator** I want to record the winnings of a draw, so that they feed into the yearly total. | `PUT /admin/draws/{drawId}/winnings` | **TicketDrawResult**, **TicketRowMatch** | 🟢 |
| **B-22** | As an **administrator** I want the winning classes of every row of the active ticket to be worked out and stored as soon as I record a draw, so that I can see what the syndicate hit without waiting for the statement. | `POST /admin/draws` | **TicketRowMatch** | 🟢 |
| **B-23** | As an **administrator** I want to record a ticket's winnings either as one sum or as the amount one row of each winning class was paid, so that I enter what the statement says and the system does the multiplying. | `PUT /admin/draws/{drawId}/winnings` | **TicketDrawResult** | 🟢 |

**Acceptance criteria:**

- B-06: `409` when a row already exists for this period — enforced through the UK, not through a check in code. A correction within the running period needs an explicit replacement reason
- B-06: exactly six distinct numbers from 1–49, stored ascending
- B-08: `409` on a duplicate draw date; numbers and bonus number (0–9) are checked against the same rules
- B-09: computes the hits per row (**TicketRowMatch**) from `Draw.numbers` and the `TicketRow` snapshots, and sums the ticket's winnings
- B-22: recording the draw evaluates the rows of the ticket covering its date — every row, not only the winning ones. The amounts stay at `0.00`: what the ticket won is not known yet, and a guessed figure would be indistinguishable from a booked one
- B-22: the draw stays `drawn`. `evaluated` says the money is booked, and that is what B-13 sums the year from
- B-22: a draw with no covering ticket is recorded all the same — nothing to evaluate against is not an error, and B-09 catches the evaluation up when the winnings arrive
- B-22: the evaluation runs on the `TicketRow` snapshots, like B-09, and through the same `WinningsDistribution` — the projection recomputes it on a rebuild, so two implementations would drift apart into different money
- B-23: `totalAmount` **or** `winningClasses`, exactly one of them — `400` when both are missing, `400` when both are sent. Class by class the total is derived, so a second figure beside it is either the same number twice or a contradiction, and nothing can tell which
- B-23: `winningClasses[].amountPerRow` is what **one** row of that class was paid, as the statement prints it. `total = Σ amountPerRow × rows of the ticket in that class`, multiplied in whole cents rather than as floats. Which rows are in which class comes from the `TicketRow` snapshots through `WinningsDistribution`, so a class no row reached contributes nothing however large its amount
- B-23: every class that was entered is recorded with what it was worth for one row, how many rows it applied to and what came of it — including the ones that reached nobody. What was typed has to stay readable next to the statement it came from
- B-23: a class listed twice is `400`. Which of the two amounts counts is not for the system to guess, and taking the last one would book half the statement

## Implicitly required

These five stories are not in the task list, but without them the data for B-01 through B-09
cannot come into being at all. They belong in the base version and are to be worked through
in this order.

| ID | Story | Endpoint | Data model | Status |
|---|---|---|---|---|
| **B-10** | As an **administrator** I want to create a tipp year with a period and a row price. | `POST /admin/tipp-years` | **TippYear** | 🟢 ♻️ |
| **B-14** | As an **administrator** I want to define a tipp year's bet periods freely, so that I decide how often a row may change. | `POST /admin/tipp-years/{id}/bet-periods` | **BetPeriod** | 🟢 |
| **B-11** | As an **administrator** I want to add a participant to a tipp year. | `POST /admin/tipp-years/{id}/members` | **Membership** | 🟢 ♻️ |
| **B-12** | As an **administrator** I want to record the monthly ticket, so that fees come into being and draws can be attributed. | `POST /admin/tipp-years/{id}/tickets` | **Ticket**, **TicketRow**, **Fee** per participant | 🟢 |
| **B-13** | As an **administrator** I want to record the yearly distribution, so that every participant receives their share. | `POST /admin/tipp-years/{id}/payout` | **Payout**, **PayoutShare** | 🟢 |
| **B-18** | As an **administrator** I want to set the status of a tipp year, so that I can start it, end it and correct a wrong booking. | `PUT /admin/tipp-years/{id}/status` | **TippYear** | 🟢 |
| **B-21** | As an **administrator** I want to create a participant and see the existing ones, so that I can add them to a tipp year. | `POST`/`GET /admin/participants` | **Participant** | 🟢 |
| **B-25** | As an **administrator** I want to correct a participant's name and record that somebody no longer plays, so that the roster matches the syndicate without losing what has been booked. | `PUT /admin/participants/{id}`, `PUT /admin/participants/{id}/status` | **Participant** | 🟢 |

**Acceptance criteria:**

- B-18: **every** transition between `planned`, `running`, `closed` and `distributed` is allowed, backwards ones included — a year closed too early has to be reopenable, and the correction belongs in the event history rather than in a manual `UPDATE`
- B-18: **at most one tipp year is `running` at a time.** `409`, naming the year that blocks it. Enforced through the unique key `tipp_year.running_marker`, not through the check in the handler — that one only serves the error message and does not hold against concurrency
- B-18: `400` on an unknown status, `409` on setting the status that is already set (an event that describes no change does not belong in the history), `404` on an unknown tipp year
- B-14: periods have to lie within the tipp year and must not overlap each other. A single period spanning the whole year is allowed and yields "one row per year"
- B-12: bundles the rows of all participants with an active **Membership** whose **BetPeriod** contains the ticket's `period_start`; `total_cost = row_count × draw_count × ticket_cost_per_row + processing_fee`; creates one **Fee** per participant over an equal share of it
- B-12: what is handed in is a day, a **Laufzeit** in weeks (1–52) and the draw days (`wednesday`, `saturday`, `both`). `period_end` and `draw_count` are **derived** — `period_start + duration_weeks × 7 − 1` days, and `duration_weeks` times one or two draws. 6 aus 49 is drawn on Wednesday and Saturday including holidays, so a week of the Spielauftrag holds exactly one draw per chosen day; a caller cannot send a draw count the ticket does not play
- B-12: the **Bearbeitungsentgelt** is charged once per Spielauftrag, not per row and not per draw. Its rate comes from the tipp year's price list, picked by the length of this ticket — at most seven days inclusive is single-week, anything longer multi-week — and is recorded on the ticket, so a later change to the rates does not rewrite what a submitted ticket cost
- B-12: because the fee is charged once, the total is generally **not** a multiple of the row count. The shares are split in whole cents with the remainder on the first, so they add back up to the ticket exactly — rounding each share separately would under-bill the syndicate by a cent per ticket
- B-12: the rows are copied into **TicketRow** as a snapshot — a later correction to the **BetRow** does not change submitted tickets
- B-13: `total_winnings` = the sum of all **TicketDrawResult** of the year; `share_per_participant = total_winnings / participant_count`; the rounding difference goes onto the first share
- B-13: `409` when the tipp year is not `closed`, or a distribution already exists
- B-21: no `user_id` — identity comes from the Keycloak token, and the `user` table dates
  from before that and is no longer written by any projector. Linking an account is E1-01
- B-21: the participant is active immediately. `ParticipantApproved` models the approval of a
  **self**-registration, and that is E1; whatever the administrator records is approved by
  the act of recording it
- B-21: `400` on a display name under 2 or over 50 characters (`DisplayName`)
- B-21: both are admin-only. A participant must not enumerate the others (B-16)
- B-25: the name is the only thing corrected. It is not copied into any read model — fees, rows, memberships and payout shares join the participant — so one write fixes it everywhere, and the event keeps the previous name for the history
- B-25: `409` on renaming to the name they already have, and on setting the status they already have. An event that describes no change does not belong in the history
- B-25: **there is no delete.** A participant is referenced by memberships, bet rows, fees and payout shares of played years; removing the row would take those with it or leave them pointing nowhere. `is_active = false` says "plays no more" and changes only what happens next
- B-25: an inactive participant is refused by **B-11** (`409`) and left out of `GET /admin/participants?active=true`, which is what the pickers ask for. The roster itself shows everybody — otherwise nobody could be brought back
- B-25: `isActive` is required on the status route. A body without it would deactivate somebody by default, which is not a request anybody made

## Turn of the year — specified, not implemented yet

Both stories build on B-18 and belong together: B-19 defines *what* runs next, B-20 makes the
change unattended.

| ID | Story | Endpoint | Data model | Status |
|---|---|---|---|---|
| **B-19** | As an **administrator** I want to assign a successor to a running tipp year, so that it is settled which year runs next. | `PUT /admin/tipp-years/{id}/successor` | **TippYear**, new column `successor_id` | 🔵 |
| **B-20** | As an **operator** I want an expired tipp year to be ended automatically and the configured successor to be started, so that the change does not depend on a manual booking. | no endpoint — a scheduled run | **TippYear** | 🔵 |

**Acceptance criteria:**

- B-19: the successor has to be `planned` and must not be the year itself; its period has to lie **after** that of the running year
- B-19: a tipp year is the successor of at most one other — to be enforced through a unique key on `successor_id`, not through a check in the handler
- B-19: the successor can be overwritten and removed for as long as the change has not happened
- B-20: "expired" means `end_date < today` **and** status `running`
- B-20: the run sets the year to `closed` and, if a successor is configured, that one to `running` — both through the same path as B-18, so that the rule "only one running year" and the event history stay the same
- B-20: the run is **idempotent** and has to run a second time without consequence; it may well run several times in parallel, so the unique key has to decide
- B-20: distribution does **not** happen automatically. B-13 demands an explicit confirmation and cannot be taken back — that stays a human decision
- B-20: the run writes into the command history like any other write, so that it is recognisable afterwards that an automation made the booking and not an administrator

**Open design questions:**

- Where does B-20 run? A cron in the `php` container is the obvious thing; an endpoint
  triggered by an external scheduler would be more testable and more visible in operation.
- What happens to an expired year **without** a successor? Proposal: close it and start
  nothing — then operations come to a halt and get noticed, instead of quietly carrying on.
- What if the successor has no bet periods yet? Then it would accept tickets, but no row
  would be valid. Presumably B-20 should not start it in that case.

## Cross-cutting

| ID | Story | Implementation | Status |
|---|---|---|---|
| **B-15** | As a **user** I want to log in through SSO. | OIDC/Keycloak, `participant_id` as a JWT claim | 🟢 ♻️ |
| **B-16** | As a **participant** I want to be sure nobody sees my data. | `403` when `participantId` in the path ≠ the claim | 🟢 ♻️ |
| **B-17** | As an **operator** I want the admin area to be role-protected. | `realm_access.roles` contains `admin` | 🟢 ♻️ |

---

# E1 — self-service

Everything that gives participants write access to their own data.

| ID | Story | Endpoint | Status |
|---|---|---|---|
| **E1-01** | Self-registration as a participant | `POST /registrations`, `GET /registrations/me` | 🟢 |
| **E1-02** | See and change one's own profile | `GET`/`PUT /participants/{id}` | 🔵 |
| **E1-03** | Choose one's own bet row for the next period | `PUT /participants/{id}/bet-row` | 🔵 |
| **E1-04** | Request to join the next tipp year | `POST /tipp-years/{id}/join-requests` | 🔵 |
| **E1-05** | Declare leaving at the end of the year | `POST /tipp-years/{id}/leave-requests` | 🔵 |
| **E1-06** | Report a payment oneself | `POST /participants/{id}/fees/{feeId}/payment` | 🔵 |
| **E1-07** | Be notified about due fees, evaluated draws and the distribution | `GET .../notifications`, SSE stream | 🔵 |
| **E1-08** | Find and inspect open syndicates | `GET /tipp-years` | 🔵 |
| **E1-09** | Export one's own data and demand deletion (GDPR) | `GET .../data-export`, `DELETE /participants/{id}` | 🔵 |

**Acceptance criteria (E1-01):**

- the registration creates a **pending** participant — a request, not a member. `pending` is a status of its own precisely because "not approved yet" and "left the syndicate" are different things, and a roster that cannot tell them apart would either hide the request or offer a stranger for a tipp year
- the account comes from the token's `sub` and is **never** read from the body. A caller who could name somebody else's account would be occupying it before they get there
- `409` on a second registration from the same account — checked for the sentence, held by the unique key `participant.keycloak_subject`
- the administrator decides through B-25's status route. Saying yes to a **pending** participant records `ParticipantApproved` rather than `ParticipantStatusChanged`: an audit trail that cannot tell an approval from a reactivation has lost the more interesting of the two. Saying no makes them `inactive`
- **identity no longer depends on the `participant_id` claim.** Where a token carries none, the kernel resolves the account through `participant.keycloak_subject`. That is what makes this self-service: before it, becoming visible to the application meant an administrator typing an id into a Keycloak user attribute (see [KEYCLOAK.md](KEYCLOAK.md))
- a pending participant is resolved as well — every rule that matters checks the status, and answering "you are nobody" to somebody whose registration is on a desk would be a lie
- `GET /registrations/me` answers `registered: false` rather than `404`: asking is legitimate for anyone signed in, and that is the answer

**Why the rest is not in the base version:** E1-03 through E1-05 shift decisions from the
admin to the participant and need an approval flow. In the base version the admin records
everything directly.

---

# E2 — sports-betting game

The original sports-result betting game as a second mode of play alongside the lottery.

| ID | Story | Endpoint |
|---|---|---|
| **E2-01** | Manage the mode of play and the scoring rules | `GET`/`POST /admin/game-types`, `PointConfiguration` |
| **E2-02** | Create and import sports events with a betting deadline | `POST /admin/games/{id}/events`, `.../events/import` |
| **E2-03** | Place a bet per event and change it until the deadline | `POST`/`PUT .../predictions` |
| **E2-04** | Record the result and compute the points | `POST /admin/events/{id}/results`, `.../scores/calculate` |
| **E2-05** | See a game's leaderboard | `GET .../leaderboard` |
| **E2-06** | See the others' bets after the deadline | `GET .../predictions/peers` |
| **E2-07** | Browse the game catalogue | `GET /games`, `/games/{id}/events` |

**The structural difference to the lottery:** there a bet is `(participant, event)` and
changeable per event. In the lottery it is `(participant, bet period)` and changeable only
with the period. Running both models side by side means keeping `BetRow` and `Prediction` as
separate aggregates — not generalising one of them.

---

# Operations (across all stages)

| ID | Story | Endpoint | Status |
|---|---|---|---|
| **OPS-01** | Query the processing state of a command | `GET /commands/{commandId}` | 🟢 |
| **OPS-02** | Be able to repeat commands with an `Idempotency-Key` | The header on all commands | 🟢 |
| **OPS-03** | Inspect the event history of an aggregate | `GET /admin/audit/{type}/{id}` | 🟢 |
| **OPS-04** | Monitor and rebuild projections | `GET /admin/projections`, `POST .../{name}/rebuild` | 🟢 |

**The command log (OPS-01, OPS-02).** The `Kernel` runs every route marked as a `command`
under the `command_log`. The `Idempotency-Key` is claimed **before** execution — the unique
key on the column decides the race. Checking first and executing afterwards would leave a
window in which two parallel retries both get through; exactly the double booking the key
exists to prevent. A retry returns the stored response with its original status code and the
header `Idempotent-Replay: true`.

The response's `commandId` is the primary key in `command_log` — the handler does create one
of its own, but the kernel overwrites it with the logged one so that `GET /commands/{id}`
actually finds it.

**Honest about asynchrony:** the API describes commands as asynchronous. This implementation
writes synchronously — by the time the caller holds the `202` the command is already
`completed`. `projectionsUpToDate` is therefore always `true`. The endpoint remains useful
nonetheless: that is where a retry looks up what the first attempt produced.

**Projections (OPS-04).** Seven projectors, one per read model. The repositories continue to
write their projection synchronously while saving — a load right afterwards has to see it.
The projectors are the *second* path to the same rows: they replay the event log.

Each repository also records how far its read model is current, in the same transaction. Until
that was added, `projection_state` moved only on a rebuild, so this endpoint reported a lag
that grew with every command while the data was perfectly current — a permanent false alarm,
which is worse than no alarm because a projection that really stopped being written looked no
different. A projection advances only on its own writes, so it may sit below the head with a
lag of `0`: `lag` counts only the events it consumes.

Two paths to the same tables drift apart when nobody looks. That is why
[ProjectionRebuildTest](tests/Integration/Application/ProjectionRebuildTest.php) plays a
complete tipp year through the command handlers, photographs **all 13** read-model tables,
rebuilds from the event store and compares row by row. The only exception:
`ticket_row_match.calculated_at` — that records *when* the calculation happened, and may
change on a rebuild.

`ticket_row_match` appears in no event; only the ticket's winnings are recorded. The
projector therefore recomputes the rows — through the same domain service
`WinningsDistribution` the command handler uses. That is exactly why it was pulled out of the
handler.

**A rebuild reaches downwards.** The read models hang together through foreign keys with
`ON DELETE CASCADE`: emptying `participant` also empties `membership`, `bet_row` and `fee`.
A rebuild therefore always rebuilds the dependent projections along with it — otherwise they
would stay empty and nobody would notice. The response lists everything that was actually
rebuilt.

---

# Migration of the existing code

The change of course affects the domain, not the architecture. CQRS, event sourcing, the
repository structure, the HTTP layer and the test scaffolding stay unchanged.

## Directly reusable

| Building block | Note |
|---|---|
| [Db.php](src/Infrastructure/Persistence/Db.php), [Row.php](src/Support/Row.php) | Typed PDO access — domain-neutral |
| [Input.php](src/Presentation/Http/Input.php), [JsonResponse.php](src/Presentation/Http/JsonResponse.php), [Request.php](src/Presentation/Http/Request.php) | The HTTP layer — domain-neutral |
| [Router.php](src/Presentation/Router/Router.php) | The structure stays, only the routes change |
| [Container.php](src/Infrastructure/DI/Container.php), [Config.php](src/Infrastructure/Config/Config.php) | Wiring |
| [PdoEventStore.php](src/Infrastructure/EventStore/PdoEventStore.php) | The event store including optimistic locking |
| `Domain/Exception/*` | The exception hierarchy |
| [IntegrationTestCase.php](tests/Integration/IntegrationTestCase.php) | The test base including the skip behaviour |
| `Participant`, `User`, `Fee` | Unchanged in domain terms; Fee gets `ticket_id` instead of `betting_game_id` |

## Renamed and rebuilt

| Previously | Becomes | Change |
|---|---|---|
| `BettingGame` | **TippYear** | A period + row price instead of a game type and fee rhythm |
| `GameParticipation` | **Membership** | Refers to the tipp year instead of the game |
| `Prediction` | **BetRow** | **Fundamentally**: no more `event_id`, a `bet_period_id` instead; six numbers instead of free JSON; the UK enforces one row per period |
| `Event` | **Draw** | Draw date, numbers, bonus number — no betting deadline, because betting does not happen per draw |
| `Result` | Merges into **Draw** | The draw *is* the result. `TicketDrawResult` is new and means the winnings, not the result |
| `ParticipantScore` | **TicketRowMatch** + **PayoutShare** | Hits per row and draw on the one hand, the yearly share on the other |

## Dropped in the base version, returns in E2

`GameType`, `PointConfiguration`, `PrizeDistribution`, the leaderboard, the peer view of the
bets and the entire game catalogue. The code stays in the repository but is no longer routed.

## New

**Ticket**, **TicketRow**, **TicketDrawResult**, **TicketRowMatch**, **Payout**,
**PayoutShare**. That is the core of the lottery logic and has no counterpart in the previous
model.

## Effect on the existing code

| Area | Effect |
|---|---|
| Tests | Domain and infrastructure tests stay; sport-specific tests move to E2. Currently 456 test methods (258 unit, 198 integration) |
| `demo/` | The read-only demo for Prediction/Result disappeared with the change of course and has not been replaced |
| [betting_game_api.yaml](betting_game_api.yaml) | Rewritten onto the base version (v2.6.0, 25 paths, 28 operations; `/health` is deliberately absent). The sport-driven v1.1 is ready as [betting_game_api_e2_sports.yaml](betting_game_api_e2_sports.yaml) for E2 |
| PHPStan level 10, PSR-12 | Unchanged and still met |

---

# State of implementation

| Stage | Stories | Done |
|---|---|---|
| Base | 18 | **18** — all |
| E1 | 9 | 1 — E1-01 (self-registration) |
| E2 | 7 | partly present, but no longer routed |
| Operations | 4 | **4** — all |

The base version is therefore complete and can be played through without reaching into the
database. The existing code covers sports betting (E2) fairly thoroughly — of that, it is
mainly the infrastructure that is usable for the base version, not the domain logic.

## Layers per story

| Story | Route | Command | Query |
|---|---|---|---|
| B-01 | `GET /participants/{id}/bet-row` | — | `GetBetRowHandler` |
| B-02 | `GET /participants/{id}/memberships` | — | `GetMembershipsHandler` |
| B-03 | `GET /participants/{id}/fees` | — | `GetParticipantFeesHandler` |
| B-04 | `GET /participants/{id}/payout-share` | — | `GetPayoutShareHandler` |
| B-05 | `GET /tipp-years/{id}/draws` | — | `GetDrawsHandler` |
| B-24 | `GET /tipp-years/{id}/draws` | — | `GetDrawsHandler` |
| B-26 | `GET /tipp-years/{id}/draws` | — | `GetDrawsHandler` |
| B-06 | `PUT /admin/participants/{id}/bet-row` | `AssignBetRowHandler` | — |
| B-07 | `PUT /admin/fees/{id}/payment`, `GET /admin/fees` | `RecordFeePaymentHandler` | `GetFeesHandler` |
| B-08 | `POST /admin/draws` | `RecordDrawHandler` | — |
| B-22 | `POST /admin/draws` | `RecordDrawHandler` | — |
| B-09 | `PUT /admin/draws/{id}/winnings` | `RecordDrawWinningsHandler` | — |
| B-23 | `PUT /admin/draws/{id}/winnings` | `RecordDrawWinningsHandler` | — |
| B-10 | `POST`/`GET /admin/tipp-years` | `CreateTippYearHandler` | `GetTippYearsHandler` |
| B-11 | `POST /admin/tipp-years/{id}/members` | `AddMemberHandler` | — |
| B-12 | `POST /admin/tipp-years/{id}/tickets` | `SubmitTicketHandler` | — |
| B-13 | `POST /admin/tipp-years/{id}/payout` | `DistributePayoutHandler` | — |
| B-18 | `PUT /admin/tipp-years/{id}/status` | `ChangeTippYearStatusHandler` | — |
| B-14 | `POST`/`GET /admin/tipp-years/{id}/bet-periods` | `CreateBetPeriodHandler` | `GetBetPeriodsHandler` |
| B-21 | `POST`/`GET /admin/participants` | `CreateParticipantHandler` | `GetParticipantsHandler` |
| B-25 | `PUT /admin/participants/{id}`, `…/status` | `RenameParticipantHandler`, `ChangeParticipantStatusHandler` | — |

Handlers return a `CommandResult` respectively a `QueryResult`; commands answer with `202`,
queries with `200`. The response's `commandId` is by now the primary key in `command_log`
(OPS-01) — it is still not linked to `EventStore.causation_id`.

**The base version can be played through completely over HTTP.** The two gaps that long
prevented that are closed: the tipp year's lifecycle through B-18
(`PUT /admin/tipp-years/{id}/status`) and creating a participant through B-21
(`POST /admin/participants`). A walkthrough therefore no longer needs a hand-written
`INSERT` — see [QUICKSTART.md](QUICKSTART.md).

**Self-registration is in as of E1-01.** `POST /registrations` creates a pending participant
for the account that asked; the administrator decides through B-25's status route. B-21 is
still the administrator's own way in — for somebody who has no login, or none yet.

## The HTTP layer

The `Kernel` handles routing, authentication, the role check and error mapping; `index.php`
is now only the bridge to PHP's globals. That makes the whole chain testable without a web
server.

`ErrorMapper` is the only place that knows HTTP codes — handlers throw domain exceptions:

| Exception | HTTP |
|---|---|
| `UnauthorizedAccessException` | 403 |
| `EntityNotFoundException` | 404 |
| `InvalidInputException`, `InvalidArgumentException` | 400 |
| `BusinessRuleViolationException` (incl. `DuplicateEntryException`) | 409 |
| `ConcurrencyException` | 409 |
| everything else | 500 (message only in debug mode) |

`DuplicateEntryException`: rules such as "one row per participant and period" live in the
schema, not in code. Without it the application layer would have to catch a `PDOException`
and read SQLSTATE to recognise that a *domain rule* said no.

**Access protection.** Identity comes from the token, never from the path — otherwise the
ownership check would always confirm itself. `Authorization::requireSelf` is deliberately
strict: an admin does not get through there either, because that is what the admin endpoints
are for. The check runs **before** the query, otherwise a 404 would already reveal that
nothing exists for someone else's participant.

## The token signature

Identity comes from the token — so every rule above hangs on the token really coming from
Keycloak. Until [TokenVerifier](src/Infrastructure/Auth/TokenVerifier.php) existed, the
application read the claims and believed them: anyone could issue themselves a
`participant_id` and the role `admin`, and B-15 through B-17 were decoration.

Verification happens in this order:

| Check | Against what |
|---|---|
| `alg` against an allowlist | `alg: none`; HS256, with the public key as the "secret" |
| Signature against the public key from the JWKS | forged and subsequently altered tokens |
| `exp`, `nbf`, `iat` (with leeway) | expired tokens; clock drift |
| `iss` verbatim | a validly signed token from the wrong realm |
| `aud`, where configured | a token for a different client |

**The allowlist can only contain asymmetric algorithms** — the constructor rejects anything
else. Both classic forgeries therefore fail at the same place, and an `HS256` in the
configuration shows up at startup rather than on the request that would have been forged
with it.

The key always comes **from the key set, never from the token**. An unknown `kid` triggers
exactly one refetch (Keycloak signs with the new key as soon as it rotates) — a throttled
one, because the `kid` sits in the token the caller writes.

An unreachable Keycloak is **503, not 401**: a 401 would tell every client its intact token
was broken, and send it to log in again at precisely the place we already know is not
working. A last-known key set survives an outage — signing keys rotate monthly, tokens expire
within an hour.

ES\* and PS\* are rejected rather than waved through; Keycloak's default is RS256.

## Monetary amounts

`EvenSplit` divides in whole cents and puts the remainder onto the first share. Dividing in
floating point and rounding per share creates or destroys money: €100.00 across three gives
three times €33.33 and one cent disappears. This affects the yearly distribution (B-13) and
the split of a ticket's winnings across the rows (B-09).

## The persistence layer

| Aggregate | Repository | Projections its stream writes |
|---|---|---|
| `TippYear` | `TippYearRepository` | `tipp_year`, `membership`, `payout`, `payout_share` |
| `BetPeriod` | `BetPeriodRepository` | `bet_period` |
| `BetRow` | `BetRowRepository` | `bet_row` |
| `Ticket` | `TicketRepository` | `ticket`, `ticket_row` |
| `Draw` | `DrawRepository` | `draw`, `ticket_draw_result`, `ticket_row_match` |
| `Fee` | `FeeRepository` | `fee` |
| `Participant` | `ParticipantRepository` | `participant` |

**Two decisions that are easy to miss while reading:**

A new aggregate is written with a plain `INSERT`, a loaded one with an `UPDATE`. No
`ON DUPLICATE KEY UPDATE` — that would hit *every* unique key and, on a second bet row for
the same period, would silently overwrite the existing row instead of raising the 409 from
B-06's acceptance criterion.

Appending and writing the projection run in **one** transaction. Otherwise a row rejected by
the unique key would leave a `bet_row.assigned` event in the store that describes no row.

## Tests

456 test methods (258 unit across 22 files, 198 integration across 17 files). The integration
tests need a database and skip themselves when none is reachable — so `make test` stays green
without one too.

The frontend has its own suites: Vitest for composables, stores and the router guard,
Playwright for the pass against the running stack. See [FRONTEND.md](FRONTEND.md),
section "Testing".

The handlers are tested with **real** repositories against a real database. With mocked
repositories hardly anything would be left: which rows a query returns, which unique key
bites and whether a projection ends up consistent can only be answered by a database.

```sh
make test-db-start     # MariaDB 11.4 on port 3307 with the schema loaded
make test-integration
make test-db-stop
```
