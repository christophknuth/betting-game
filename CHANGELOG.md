# Changelog

A chronicle of the larger rebuilds, newest first. The current state is in
[README.md](README.md) and [ARCHITECTURE.md](ARCHITECTURE.md) – this document only records
what was changed when, and why.

---

## Nobody types a primary key any more (2026-08-02)

Six fields across five views asked for a database id. `Tippjahr` was a number input, and the
number wanted was the tipp year's primary key — which a participant has no way of knowing and
which nothing on the screen offered. The same for `Teilnehmer` in the fee ledger and
`Tippperiode` in the bet row view. They were lookups that could not be performed.

`TippYearPicker` answers it for the participant views from the caller's own memberships:
the honest list, because one cannot ask about a year one did not play, and the only one
available — no participant-facing endpoint lists tipp years. `DrawsView` had already built
this inline; it now uses the shared component and is shorter for it. The admin fee ledger
chooses from `GET /admin/tipp-years` and `GET /admin/participants`, which the neighbouring
admin views were already loading.

**`BetRowView` lost its period field rather than gaining a list.** Nothing lists a
participant's bet periods, so that field could only be filled by guessing; the view now shows
what B-01 actually asks for, the row of the period running today. Looking at an earlier
period needs a participant-facing list of periods first, and that is a story rather than a
layout decision — noted, not invented.

The E2E suite follows the consequence rather than papering over it. The seed takes the first
free calendar year, so the running period usually belongs to a year that is not this one:
"sees the six numbers" and "says that no period is running" are now two tests, and exactly
one of them applies on any given run.

Verified: ESLint clean, Vitest 127/127, `npm run build`. Playwright not run — it needs the
whole stack up.

---

## Money is German on the way in too (2026-08-02)

Every amount in the interface is *shown* as `1,20 €` and always was — `formatAmount` runs
through `Intl` with `de-DE` and is used everywhere without exception. Every amount was *asked
for* as `1.20`: five fields, all plain `<input type="number">`, all with dot-written defaults
like `1.20` and `0.60`.

That is not only inconsistent, it drops input. The value of a `number` input is defined to
use a dot whatever the locale, so a browser that takes the definition literally answers a
typed `1,20` with an empty string — the field simply clears, says nothing, and the form goes
off with no price on it.

**`MoneyInput` is the one way in now.** A text field with `inputmode="decimal"`, so phones
still offer the numeric keypad; the `€` beside the entry rather than inside it, where it
would be read back in on the next keystroke; and on blur the entry rounded to cents and
rewritten as `1,20`. The rounded figure is the one emitted, so what the field shows and what
the API is sent cannot drift apart.

`parseAmount` decides what counts: comma **and** dot are taken as the decimal separator,
because the comma is what the label implies and the dot is what years of forms have trained
into people's fingers. Where both appear the last one wins and the other is dropped as
grouping — that is what pasting a formatted `1.234,56 €` back into a field looks like. A lone
dot is therefore always a decimal point; `formatDecimal` leaves grouping out entirely, so
nothing has to be guessed on the way back.

Writing the tests found a real fault in the first version: `onBlur` read the amount off the
model, which is only as current as the parent's last echo of it, so a field whose parent had
not echoed reverted to the old figure on blur. It reads the text it is showing instead — the
field is what was typed.

Verified: ESLint clean, Vitest 127/127 (twenty-two new for the field and the parser), and
`npm run build`.

---

## A draw shows the rows it was played against (2026-08-02)

**B-24.** A draw showed six balls, a total and — after B-09 — a table of winning classes.
What it never showed was the thing the numbers are actually compared against: the rows the
syndicate had on the ticket. Whether anyone came close was not answerable from the screen.

Every row of the covering ticket is now listed with the draw, with the name of whoever plays
it, the numbers as they were printed on the ticket, the hits and the winning class. Losing
rows included — "no class" is a result, and a list of only the winners would look like a
ticket that never carried the others.

**The marking is doubled**, and both halves earn their place. A row that reached a class is
highlighted as a whole and says which one; inside every row the numbers that were *not* drawn
step back into grey, so the hits keep the syndicate's colour and a near miss can be read
rather than counted.

**The ticket had to stop hanging off the winnings.** `draw.ticket` came from
`ticket_draw_result`, which does not exist until B-09 has been recorded — so before that the
draw claimed no ticket had taken part, although one had. It is joined by its period now, the
same rule `findCovering` and the projector use. The consequence is deliberate and worth
knowing when reading the API: `totalAmount` is `null` while no winnings are recorded, where
it used to be `0.00`. Zero is a statement about a draw somebody has looked at.

That also made `GetDrawsHandler`'s ticket repository redundant — `rowCount` comes from the
same join now — so the handler is down to two dependencies.

**Both draw views show it**, the participant's and the administrator's, through one
`DrawRows` component. Which means every participant sees every row of the ticket, with names:
a deliberate widening, noted as such in B-24's acceptance criteria. The rows of one ticket
are the syndicate's shared business — it is handed in as one slip and everyone pays a share
of it — but that is a decision, not a technicality, and it is written down where it can be
revisited.

Verified: PHPStan level 10 clean, phpcs clean, PHPUnit 430 with `--fail-on-skipped`, ESLint
clean, Vitest 105/105 including seven new for the row list.

---

## The winnings are entered the way the statement reads them (2026-08-02)

**B-23.** The lottery statement comes in two shapes. Sometimes it is one figure for the
Spielauftrag, sometimes it lists what each winning class paid. The API only ever took the
first: `totalAmount` was required, and whoever held the detailed statement had to add nine
numbers up by hand before they could enter anything — which is precisely the arithmetic the
system should be doing.

`totalAmount` is now optional in the presence of `winningClasses`, and the new `DrawWinnings`
value object decides between them. Without a total the classes are added up into one, **in
whole cents**: three times 0.10 is not 0.30 in binary floating point, and the year's total is
the sum of these.

**Sending both keeps its older meaning**, which the existing tests were right to defend. A
breakdown may account for only part of the total — 500 won, of which 300 is attributable to
class 5 — and the remaining 200 counts towards the year without any row being able to claim
it. That predates this change, and the first version of the rule here broke it by insisting
the two figures match. What is rejected is narrower and harder to argue with: a breakdown
adding up to *more* than the ticket won, a class listed twice, and neither figure at all.

**In the interface, the choice is a pair of radio buttons.** `WinningsEntry` offers either
one amount or one field per winning class with the sum computed underneath, and sends exactly
one of the two shapes — never both, because the two could then contradict each other on the
way out. `AdminDrawsView` passes the payload through unchanged rather than deciding again
locally what the API already decides.

Verified: PHPStan level 10 clean, phpcs clean, PHPUnit 428 with `--fail-on-skipped` (eleven
new), ESLint clean, Vitest 98/98 including six new for the entry form.

---

## The rows are evaluated with the draw, not with the money (2026-08-02)

**B-22.** The hits per row were worked out when the winnings were recorded, which is days
later — the statement arrives when it arrives. Until then the system held the drawn numbers
and the row snapshots and said nothing about how they compare, although that is a pure
function of the two.

Recording a draw now evaluates every row of the ticket covering its date and stores hits,
Superzahl and winning class in `ticket_row_match`. Every row, not only the winning ones: "no
class" is a result too, and the read model would otherwise be unable to tell a losing row
from one nobody looked at.

**The amounts stay at `0.00`, and the draw stays `drawn`.** What the ticket won is not known
yet, and a guessed figure in an amount column is indistinguishable from a booked one.
`evaluated` says the money is in, which is what B-13 sums the year from — moving that
forward would have made the status mean two things.

B-09 recomputes the same matches with the money in hand rather than updating them in place.
The hits are a function of the numbers and cannot have changed; recomputing is also what
catches up a draw that was recorded before its ticket was handed in. A draw no ticket covers
is recorded all the same — there is simply nothing to evaluate it against yet.

**The projector had to learn the same trick**, or a rebuilt read model would show no hits
for every draw whose winnings are still outstanding. `ProjectionManager` already guarantees
what that needs: the ticket projection runs before the draw projection, so the rows are
there when the draw is replayed. `ProjectionRebuildTest` compares every read model table
column by column and covers the new case through the draw it deliberately leaves unpaid.

While in there, `TicketRepository::rowIdsOf()` became `snapshotRowsOf()` and returns the
rows themselves in `ticket_row_id` order. Three callers assembled that list, one of them by
joining bet row ids back to snapshot ids by hand — and the order is not cosmetic, because
`WinningsDistribution` puts the remainder cent on the first winning row.

Verified: PHPStan level 10 clean, phpcs clean, PHPUnit 413 with `--fail-on-skipped` (four
new, one pre-existing deprecation).

---

## The six numbers are picked, not typed (2026-08-02)

**A Lottoschein is a grid, so the form is one too.** Both places that took the six numbers —
B-06 assigning a row, B-08 recording a draw — had a text field that accepted `3 12 19 27 33
45`, or a seventh number, or a 50, or the same number twice. `NumberGrid` puts the 49
numbers on a 7×7 grid instead: a click picks one, a second click releases it, and once six
are picked the rest lock. The picked ones stay clickable, because that is the only way back
out of a full grid.

The selection is held ascending whatever order the clicks came in — the order the domain
keeps it in, so no view has to sort on the way out.

**What the grid enforces, nobody has to check any more.** `parseNumbers` read six numbers
out of a text field and rejected everything that was not six distinct numbers from 1 to 49;
that guard is gone along with the field it guarded, and both views dropped their error state
with it. What is left is the one rule a grid cannot meet by construction — "not yet six" —
and that one the submit button carries, next to a counter that says how many are still
missing. The domain still checks all of it in `LottoNumbers`; it always was the authority,
and the client was only ever saving it a round trip.

B-06's acceptance criterion moved test file with its guard: from
`support/format.spec.js` to `components/NumberGrid.spec.js`, where it is now checked against
the thing that meets it.

Verified: ESLint clean, `npm run build`, Vitest 92/92 including the seven new grid tests. Not
looked at in a running stack — no container was up, and the change is confined to the SPA.

---

## The interface says less, the log says more (2026-08-01)

**The interface explained itself to people who had not asked.** Every write printed its
`commandId` with a link to the processing state. Forms carried paragraphs about the event
history, which status code an endpoint answers, that the rounding difference goes onto the
first share. A participant whose token lacked a `participant_id` claim was told exactly
that, plus a note about the realm's client scopes.

All of it true; none of it anything the reader could act on. It now answers two questions —
did it work, and if not what can I do — and the rest goes to the container's log.

**Which first had to be able to reach the container's log at all.** `LoggerFactory` wrote
into `var/log/*.log` with a rotating handler, so `docker-compose logs` showed nothing and
rotation duplicated what the runtime already does. Everything goes to stdout now, warnings
and worse to stderr, JSON in production and a readable line in development.

The `Kernel` logs every command, which is where the `commandId` went:

```json
{"message":"Command accepted","context":{"command":"AdminParticipantController::create",
 "commandId":"3b355714-…","actor":"admin","status":202,"resourceId":49},"level_name":"INFO"}

{"message":"Command rejected","context":{"actor":"admin","status":400,
 "reason":"Display name must be at least 2 characters"},"level_name":"WARNING"}
```

A rejection is a **warning**, not an error — a business rule saying no is that rule doing
its job. An idempotent replay is logged too, or a retry storm would be invisible.

The line for what stays on screen is whether a sentence changes what the reader *does*.
"Laufen darf immer nur ein Tippjahr" does, and stayed. The resource id survives in exactly
one place: after creating a participant, because it has to be typed into the realm as their
`participant_id`.

**Browser diagnostics deliberately stay in the browser.** Nothing in a SPA reaches the
container's log without being shipped to the server, and an endpoint that accepts log lines
has to work before login — which makes it a write handle on the log for anyone who finds
it. The events worth having are server-side anyway. What the participant panel used to
display now goes to the console instead.

Verified by building the production image and driving two commands through it, one accepted
and one rejected, then reading them back out of `podman logs`. Plus PHPStan level 10,
phpcs, PHPUnit 409 including the new `CommandLoggingTest`, ESLint, Vitest 94 and Playwright
12/12.

---

## One image for production, and production mode made to work (2026-08-01)

**Caddy now serves the SPA too**, which was the actual question: same origin, `/api/*` to
the PHP front controller with the prefix stripped, everything else the built SPA with
`index.html` as the fallback. The nginx container is gone, and with it CORS — the
development Caddyfile still answers `Access-Control-Allow-Origin "*"`, which has no
business in front of an authenticated API.

The runtime is **FrankenPHP**: Caddy with PHP embedded, so one process serves static files
and runs PHP. No php-fpm, no second web server, no supervisor holding two of them together.
`Dockerfile` builds SPA, vendor and runtime in three stages;
`docker-compose.prod.yml` runs it beside its database and Keycloak.

**One image has to serve more than one environment**, so the SPA's configuration moved out
of the bundle. Vite bakes `VITE_*` in at build time; the entrypoint now writes
`config.js` from the container's environment at every start, and the SPA reads that before
falling back to what was built in. `VITE_API_URL` disappeared entirely — at one origin it
calls `/api`.

**Along the way it turned out `APP_ENV=production` had never worked.** Production switches
on PHP-DI's compiled container, and compilation cannot handle a closure that captures the
outer scope — six factories were written as `function () use ($settings)`. They now resolve
`Config` from the container instead. The last one took longest to find because it was an
arrow function: `fn () => new ErrorMapper($settings->bool('debug'))` captures implicitly and
is invisible to a search for `use (`. Nothing in development notices, because compilation is
off there.

Three more things the smoke test found, each of which looks like something else:

- **APCu was missing.** Production also enables PHP-DI's definition cache, which requires
  it; without the extension the bootstrap fails before any route is reached.
- **`--classmap-authoritative` was built without the sources.** The vendor stage only had
  `composer.json`, so the classmap was empty — and authoritative means no fallback to
  PSR-4. Every `BettingGame\…` class was simply "not found". The autoloader is now dumped
  after `src/` is copied in.
- **`REQUEST_URI` needed overriding twice over.** `handle_path` strips `/api` from the path
  Caddy routes on but hands PHP the URI the client asked for, so the router 404s. And
  `php_server`'s `try_files` rewrites to `/index.php` *before* a plain `env REQUEST_URI
  {uri}` is evaluated, so the router then receives `/index.php` — same symptom, different
  cause. Capturing the URI in an explicit `route` block orders the two correctly.

Verified by building the image and driving it against the real database and Keycloak:
health, an unauthenticated `401`, an authenticated `200`, a query string, a `202` write
through the API, the SPA at `/`, a deep link, `config.js` served `no-cache` and hashed
assets `immutable`. Plus PHPUnit 405, Vitest 93, ESLint clean and Playwright 12/12 on the
development stack.

---

## A ticket costs more than its rows (2026-08-01)

**The Bearbeitungsentgelt was missing from the model entirely.** LOTTO charges a fee for
every Spielauftrag on top of the rows, and the rate depends on how long the order runs —
0.60 € for a single week, 1.00 € for a multi-week one in Schleswig-Holstein's list. The
model knew only `rows × draws × price`, so every ticket was billed short.

The rates are agreed for the season, so they live on the tipp year as a two-part price list
(`ProcessingFees`), and the rule that picks between them lives with them: a Spielauftrag
covering at most seven days inclusive is single-week. The ticket records which rate it was
charged, so a later change to the list does not rewrite what a submitted ticket cost — the
same reason the rows are copied onto it as a snapshot.

**Adding the fee exposed a cent bug that had been dormant.** `feePerParticipant()` divided
with `round($total / $rowCount)`, which was exact only because the total was a multiple of
the row count. Charged once per ticket, the fee breaks that: 3 rows × 9 draws × 1.20 plus
1.00 is 33.40, and a third of that is 11.1333… Rounding each share separately bills 33.39
and loses a cent — on every ticket, forever. The split now goes through `EvenSplit`, in
whole cents with the remainder on the first share, the same convention B-09 and B-13 use.
`feePerParticipant()` became `feeShares()`, because there is no longer one answer.

Measured on the running stack rather than argued:

| Spielauftrag | Rate | Total | Shares |
|---|---|---|---|
| 01.01.–31.01., 9 draws | 1.00 multi-week | 33.40 = 3×9×1.20 + 1.00 | 11.14 + 11.13 + 11.13 |
| 05.02.–11.02., 2 draws | 0.60 single-week | 7.80 = 3×2×1.20 + 0.60 | 2.60 × 3 |

**Two events grew a field, which is a schema change to an immutable log.** Everything
written before this carries no such key, so the projectors and the event-store deserialiser
read the new fields as nullable and fall back to zero. An integration test strips the fields
back out of stored payloads and rebuilds from them, because a rebuild is exactly when a
strict reader would have been discovered.

> **Existing databases need the columns.** `schema.sql` only runs on an empty data
> directory, so a stack that is already up keeps the old table definition and every write
> fails. Add them without losing data:
>
> ```sql
> ALTER TABLE tipp_year
>   ADD COLUMN processing_fee_single_week DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ticket_cost_per_row,
>   ADD COLUMN processing_fee_multi_week  DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER processing_fee_single_week;
> ALTER TABLE ticket
>   ADD COLUMN processing_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER draw_count;
> ```

Both fees default to zero, so a syndicate that is not charged one can ignore them; the
frontend hides the explanation entirely in that case.

Verified: PHPStan level 10 clean, phpcs clean, PHPUnit 405 with `--fail-on-skipped`, ESLint
clean, Vitest 87/87, Playwright 12/12 against the real stack, plus the two tickets above
submitted through the live API.

---

## OPS-04 reported a backlog that was not there (2026-08-01)

**`GET /admin/projections` said every projection was behind, and the gap grew with every
command.** The data was never the problem: the repositories write their read model in the
same transaction as the events, which is why a tipp year is queryable the moment its `202`
comes back. Measured on the running stack — command sent, row immediately in the read model,
`headPosition` 396 → 397, `lastProcessedPosition` unmoved at 203.

`projection_state` was written **only** by `rebuildOne()`. The normal write path never touched
it, so the endpoint computed `lag` and `upToDate` from a counter that only ever moved when an
operator rebuilt something by hand.

That was a deliberate simplification, not an oversight — `OperationsApiTest` asserted it, with
a comment explaining it. The trouble is what it costs in operation: a monitor that always
cries wolf teaches whoever reads it to ignore it, and a projection that genuinely stops being
written looks exactly the same as one that is fine.

Now each repository records the position it reached, in the same transaction as the events and
the projection rows — all three land together or not at all. The name comes from the matching
projector's `NAME` constant, so the two cannot address different rows.

Two things that look like bugs and are not:

- **A projection advances only when its own repository writes.** It can therefore sit below
  the head with a lag of `0` — `lag` counts only the events it consumes. Advancing them all on
  any write would have been the tempting shortcut, and it would mask exactly the failure this
  endpoint exists to show.
- **An ordinary write does not clear a `failed` status.** A projection left half-built by a
  botched rebuild stays flagged; that new writes keep landing does not undo it.

Verified: PHPStan level 10 clean, phpcs clean, PHPUnit 391 tests with `--fail-on-skipped`
against a real database, and measured again on the running stack — the projection jumped to
the head on the next command and stayed at `lag: 0` afterwards.

---

## Setting up a tipp year is guided now (2026-08-01)

**The order was invisible and the forms did not help.** A tipp year is only usable once four
things have happened in sequence — the year exists (B-10), it has periods (B-14), it has
members (B-11), and it is `running` (B-18) — but the page showed five forms stacked on top of
each other with nothing to say which came first. Twelve monthly periods meant twelve
submissions with hand-computed dates, and every slip came back as a `409` from the overlap
rule.

- **`TippYearSetupWizard`** walks a new year through the four steps, writing as it goes.
- **`TippYearChecklist`** shows an existing year what it is still missing, with the matching
  action inline — otherwise adding a member mid-year would have had nowhere to happen.
- Ticket (B-12) and distribution (B-13) moved into a **Laufender Betrieb** section. They are
  monthly and yearly operations, not setup, and standing in the same stack was what made the
  page read as a pile of unrelated fields.

**Periods are computed rather than typed.** `support/betPeriods.js` turns a template — whole
year, halves, quarters, months — into periods that tile the year exactly: first day to last,
each beginning the day after its predecessor ends. That tiling is the invariant the tests
check, not any individual date.

The period *count* is derived, not fixed. A tipp year is a freely defined range, so
"quarters" over an eighteen-month year is six periods; and the names describe what the dates
cover (`Jan–Mär 2027`) instead of claiming an ordinal (`Q1`) the range does not support.

**Twelve periods are twelve commands**, which needed its own composable. `useBatch` stops at
the first failure instead of pushing on — if period three overlaps, four to twelve rest on an
assumption that no longer holds — keeps what was already written, reports how far it got, and
on a retry sends only what is left rather than collecting `409`s for work that succeeded.
Each item carries its own idempotency key (OPS-02).

The checklist repeats the API's three period rules to *explain* them, next to the field
rather than after a round trip. The aggregate and the unique key still decide.

Verified: ESLint clean, Vitest 80/80 (19 of them on the tiling alone, including leap years
and ranges that are not calendar years), Vite build clean, Playwright 12/12 against the real
stack — run three times in a row, since the new spec creates a tipp year of its own and
repeatability was the thing most likely to break.

---

## The admin area became its own place (2026-08-01)

**One navigation bar served both roles, and it showed.** `Ziehungen` and `Gebühren` appeared
twice in the same bar — once as the participant's own data, once as the administrator's
ledger — told apart only by a trailing `⚙`. Eleven links stood in one row, split by a
single `|`.

The two are now separate areas, each under its own layout component:

| | `ParticipantLayout` | `AdminLayout` |
|---|---|---|
| Routes | `/bet-row`, `/memberships`, `/fees`, `/payout-share`, `/draws` | `/admin/*` |
| Chrome | light top bar, five links | dark top bar, sidebar grouped into Tippjahr / Spielbetrieb / System |
| Way out | `Verwaltung` → `/admin`, shown to admins only | `Zur Teilnehmersicht` → `/bet-row` |

Each name is unambiguous inside its own area, so the gear suffixes are gone. `App.vue` holds
no chrome any more — it is a bare `<router-view />`.

**No path changed.** The routes were nested under the layouts rather than renamed, so every
bookmark, every link in the docs and every existing test still points where it did. Vue
Router merges a parent's `meta` into its children, so the guard reads `requiresAdmin` on the
leaf exactly as before — the six guard tests passed untouched, which is what confirmed it.

**Decorative characters were moved out of accessible names.** The `⚙` and `←` sit in
`aria-hidden` spans. That was not cosmetics: Playwright matches an accessible name as a
substring by default, and `Zur Teilnehmersicht` contains the sidebar's `Teilnehmer`, so the
click was ambiguous until `navigateTo` also switched to exact matching.

New tests: `layouts/ParticipantLayout.spec.js` pins B-17 at the door (the link is offered to
an admin, withheld from a participant, and no `/admin/*` link ever leaks into the participant
navigation), and `auth.spec.js` now crosses into the admin layout and checks it actually
arrived in the other area rather than merely at another URL.

Verified: ESLint clean, Vitest 61/61, Vite build clean, Playwright 10/10 against the real
stack — run twice in a row, because this suite has broken on a second run before.

---

## The repository is English throughout (2026-07-31)

**The repository mixed two languages.** Code and commit messages were English, the project
documentation German, and in between sat German comments in `docker-compose.yml`, the CI
workflow, the shell scripts, the SQL schema and the OpenAPI descriptions. The rule in
`AGENTS.md` said so explicitly — which made the mixture deliberate, but no easier for anyone
reading the repository without German.

Everything that lands in the repository is now written in English: documentation, comments,
docblocks, the API contract and commit messages.

**One exception, and it is deliberate:** the frontend's user-facing text stays German —
labels, messages, status words and the `de-DE` date and currency formatting. That is the
language of the syndicate using the application; translating it would change the product,
not the repository. The same goes for sample data mirroring what an administrator would
actually type (`"Tippjahr 2026"`) and for the German product name *Lotto 6 aus 49*. A test
asserting on a visible label therefore contains German — that is the assertion, not prose.

The OpenAPI tag names changed with this (`Teilnehmer` → `Participant`, `Admin - Tippjahr` →
`Admin - Tipp Year`, …), which renames the groups in generated API documentation.

---

## Toolchain brought up to date (2026-07-31)

**The entire toolbox was around two years old**, in places without security patches:
Node 18 (end-of-life since April 2025), ESLint 8 (v9 runs out in August 2026), Vite 5
(three majors behind), Keycloak 23 — of all things the component that protects every route.

Updated in stages, each one verified separately, so that a failure stays attributable:

| | from | to |
|---|---|---|
| Node | 18 | 24 (active LTS) |
| PHP | 8.3 | 8.4 |
| Vite / Vitest | 5 / 1 | 8 / 4 |
| Vue / Router / Pinia | 3.4 / 4 / 2 | 3.5 / 5 / 4 |
| ESLint | 8 | 10 (flat config) |
| Keycloak (+ keycloak-js) | 23 | 26.7 |
| MariaDB / Caddy / PostgreSQL | 11.3 / 2.7 / 16 | 11.4 LTS / 2.11 / 18 |

**Four things genuinely broke along the way** — the reason to run the suites rather than
just bump tags:

- PHP 8.4 deprecates implicitly nullable parameters (`FileCache::__construct`).
- ESLint 9+ no longer reads `.eslintrc.cjs`; the configuration was rewritten.
- Keycloak 26 has replaced `KEYCLOAK_ADMIN` and `KC_PROXY`.
- PostgreSQL 18 wants the mount on `/var/lib/postgresql` instead of on `data/` — otherwise
  the container does not start at all.

The dependencies had to be resolved from scratch for ESLint 10. The new lockfile reports
**0 vulnerabilities**, against 17 before (1 critical, 14 high among them).

---

## Creating participants (B-21, 2026-07-31)

**The base version could not create a participant.** [QUICKSTART.md](QUICKSTART.md)
instructed readers to `INSERT` the rows by hand, and warned in the same breath: such rows
appear in no event and vanish on the next projection rebuild. So the only documented way
into the application was one the application itself undoes again.

- `POST /admin/participants` and `GET /admin/participants` with
  `CreateParticipantHandler` / `GetParticipantsHandler`.
- An integration test creates a participant, rebuilds the projection and checks that they
  are still there — exactly the property the manual work lacked.

**No more `user_id`.** The `user` table dates from before Keycloak and is no longer written
by any projector; identity comes from the token. `Participant` therefore models the column
as the nullable thing it had been in the schema all along ("guest participants have no
account") — until then the aggregate demanded a value nobody could supply any more. Linking
an account remains E1-01.

**A side effect: the participant-ID input fields are gone.** `AdminBetRowsView` and adding
someone to a tipp year asked for a bare number, because nothing could list participants.
Both now offer names.

---

## Deep links did not survive a reload (2026-07-31)

Vue Router starts its first navigation inside `app.use(router)` — that is, before `main.js`
had awaited the Keycloak start. The guard therefore judged a logged-in user to be anonymous,
sent the requested route to `/login`, and by the time the session came back the destination
was lost: every bookmark to a subpage and every reload of a protected page ended up on
`/bet-row`.

The guard now waits for `authStore.ready()`. Noticed while setting up the E2E tests, which
for that reason initially clicked through the navigation instead of using `page.goto`.

---

## Automated frontend tests (2026-07-31)

The frontend had none. Now: **Vitest** for composables, services, the auth store, the router
guard and `ParticipantScope`, each anchored to a user story rather than to the implementation
(OPS-02 the idempotency key, B-01 404-as-empty-state, B-04 `null` ≠ 0, B-06 the number
check, B-17 the role barrier). **Playwright** drives the pass against the real stack — a real
Keycloak login, a real API, real read models.

For that, `docker-compose.test.yml` wires up the until-then unused `docker/Dockerfile.test`
against its own MariaDB, isolated from the dev stack.

---

## The tipp year's lifecycle over HTTP (B-18, 2026-07-29)

**`TippYear::start()` and `close()` were enforced in the aggregate, but had neither a
command nor a route** — they were only ever called from tests. A tipp year created through
the API therefore stayed on `planned` and accepted no ticket; every walkthrough failed at
B-12, without any handler being broken.

- `PUT /admin/tipp-years/{id}/status` with `ChangeTippYearStatusCommand` and its handler.
- In the frontend the status column of the tipp-year list has gone from a badge to a
  dropdown.

**Every transition is allowed, backwards ones included.** `TippYear::changeStatus()` had an
allowlist per target; that is gone. A year closed too early, one started by accident, a
distribution recorded too soon — such corrections come up, and a forward-only graph does not
prevent them, it displaces them into a manual `UPDATE` that leaves no trace in the event
history. The only thing still rejected is a change to the status that is already set: an
event that describes no change does not belong in a history.

**At most one tipp year runs at a time.** Otherwise it would no longer be unambiguous which
year a draw belongs to, and it would count towards two distributions. The rule spans
aggregates and therefore does not live in the model:

- `ChangeTippYearStatusHandler` checks it and names the blocking year.
- The decision is made by the unique key `uk_single_running_year` on `tipp_year`. It sits on
  a generated column that is `NULL` outside `running` — equal `NULL`s do not collide in a
  unique key, equal ones do. So the key carries the rule without constraining the other
  states.

The check in the handler is explicitly **not** the safeguard: two simultaneous requests both
read "nothing is running" and would both get through. It exists for the error message, the
key for the truth.

Measured against the running stack: an `UPDATE` straight on the database, bypassing the
handler, fails with `Duplicate entry '1' for key 'uk_single_running_year'`. A projection
rebuild replays the status changes without violating the rule, and reproduces the state
exactly.

Two stories for the turn of the year are specified in [USER_STORIES.md](USER_STORIES.md) but
**not** implemented: B-19 (assign a successor) and B-20 (end an expired year automatically
and start the successor), together with the open design questions on them.

---

## The database still held the sports-betting schema (2026-07-29)

**Every authenticated query ended in a `500`.** Once the realm and `iss` were in order, the
next error of the same family came to light: `betting_game` held `prediction`,
`betting_game`, `game_participation`, `participant_score` and `result` — and not a single
lotto table. A query against `bet_period` threw a `PDOException`, which is not in the domain
hierarchy and therefore came out as a `500`.

`database/schema.sql` is mounted under `/docker-entrypoint-initdb.d/`, and that directory is
executed **only with an empty data directory**. The volume `db_data` predated the change of
course — so since `f1d0771` the stack had been running on the old domain's schema, without
that being noticed anywhere.

Loaded without deleting the volume: `schema.sql` starts with `DROP TABLE IF EXISTS` for
every table. The order of those `DROP`s is, however, laid out for the *new* foreign-key
graph and fails on foreign constraints — with `SET FOREIGN_KEY_CHECKS=0` for the session it
runs through.

Verified:

| Call | Result |
|---|---|
| `GET /health` | `200`, `"domain":"lotto-syndicate"` |
| `GET /participants/2/bet-row` (own data) | `404` "No tipp year covers 2026-07-29" |
| `GET /participants/1/bet-row` (someone else's data) | `403` "You may only access your own data" (B-16) |
| `GET /admin/tipp-years` without the admin role | `403` "Admin access required" (B-17) |
| `GET /admin/tipp-years` with the admin role | `200`, `{"tippYears":[]}` |
| `GET /admin/projections` | `200`, all 7 projections `upToDate` |

`AGENTS.md` section 9 records the pitfall — it applies to `db_data` and `keycloak_db_data`
alike: **both volumes survive every change to the file they were once filled from.**

Ten orphaned tables of the old domain were left behind (`prediction`, `user`, `game_type`
…). They do no harm, because no code touches them, and are still to be removed.

---

## Redirect loop after the login (2026-07-29)

**After logging in "Invalid or expired token" flashed up, then it went to the Keycloak login
and straight back again — endlessly.** Two errors that concealed each other.

**The `iss` claim did not match.** Keycloak issues the token for a browser and writes into
it the URL that browser fetched it from: `http://localhost:8090/realms/betting-game`.
`TokenVerifier` compares `iss` verbatim (`hash_equals`) and, without `KEYCLOAK_ISSUER`,
expected the value from `KEYCLOAK_URL` — that is, the *internal* hostname
`http://keycloak:8080/realms/betting-game`. The `php` service in `docker-compose.yml` set
**no** `KEYCLOAK_*` variables at all, even though `config/config.php` points out precisely
this difference in a comment. Every intact token was therefore invalid. Both addresses are
now set, each for its own job: `KEYCLOAK_URL` for reaching the JWKS, `KEYCLOAK_ISSUER` for
the identity in the token.

**The client turned that into a loop.** The response interceptor sent the user to the login
on *every* `401`. But Keycloak has a valid session, hands back the same token, and the next
request starts over — the actual error was visible for a fraction of a second. Logging in now
only happens when there is no session at all; a `401` with an existing session is a
configuration error and stays on screen. The case "the session really has expired" is now
handled by the request interceptor, at the point where it can recognise it: when
`updateToken` fails.

Because the API deliberately does not say *why* it rejects a token, `errors.js` names the
most likely cause on a `401` — on the client, where that gives nothing away.

---

## The realm export made authorisation ineffective (2026-07-29)

**No token from this realm ever carried `participant_id`, `realm_access.roles` or
`preferred_username`.** Noticed through the message "this token carries no `participant_id`
claim" in the interface — the cause lay deeper and affected the backend just as much.

The export defined a top-level `clientScopes` block with the one scope `participant_id`.
Keycloak reads such a block as *the complete list* of the realm's client scopes and then does
not create the built-in ones (`profile`, `email`, `roles`, `web-origins`, `acr`) at all. The
frontend client's `defaultClientScopes` therefore referred to five scopes that did not
exist — and Keycloak discards such references silently. The client ended up with **zero**
assigned scopes.

Measured against the running realm, not the export:

```
GET /admin/realms/betting-game/client-scopes
  → offline_access, participant_id          (instead of additionally profile, email, roles, …)
GET /admin/realms/.../clients/{id}/default-client-scopes
  → []
```

**The effect was not cosmetic.** Without `realm_access.roles`,
`Authorization::requireAdmin()` returns `403` for everyone; without `participant_id` the same
holds for B-01 through B-04. The entire permission check was ineffective — not too lax, but
completely shut: no route with an identity or role reference was usable. No error appeared
anywhere, because from each individual component's point of view everything was correct.

- The `clientScopes` block was removed, so that Keycloak creates its built-in scopes.
- The `participant_id` mapper now hangs **directly off the client** (`protocolMappers`). A
  mapper on the client cannot point into the void; a scope reference can.
- `KEYCLOAK.md` describes the trap, the command to check it on the running realm, and the
  re-import.

**The change only takes effect after a re-import.** `--import-realm` only imports when the
realm does not exist yet, and it lives in the volume `betting-game_keycloak_db_data`.
Commands in [KEYCLOAK.md](KEYCLOAK.md).

---

## ESLint for the frontend (2026-07-29)

**The lint script was in `package.json` without a configuration existing** — it always
failed and was therefore never used.

- [`frontend/.eslintrc.cjs`](frontend/.eslintrc.cjs) with `eslint:recommended` +
  `plugin:vue/vue3-recommended`, the strictest of the three Vue presets. One single
  exception: `vue/multi-word-component-names` permits `App`.
- `.cjs`, because `package.json` declares `"type": "module"`.
- `npm run lint` now only checks, `npm run lint:fix` fixes. Previously the lint script
  carried a `--fix` — a check command that modifies files is no use in a pipeline.

**The result of the first check: 515 violations, 0 of them errors.** All came from four
formatting rules (`max-attributes-per-line`, `singleline-html-element-content-newline`,
`html-self-closing`, `multiline-html-element-content-newline`) and were automatically
fixable. That nothing came out of `eslint:recommended` and the Vue error rules means: no
unused variables, no unknown identifiers, no missing `:key`.

The formatting rules were deliberately **not** switched off, even though they make the
templates longer. They replace the formatter this project does not have.

**One place was broken afterwards and was fixed by hand:** in `DrawsView` the label
"5 Richtige + Superzahl" sat as two markup fragments whose spacing depended on where the line
broke. The formatter may break elsewhere — the string is now assembled in JavaScript instead
of out of markup.

---

## The frontend moved onto the lotto domain (2026-07-29)

**The SPA called endpoints that had not existed since the change of course.** Predictions,
scores and games — every domain request ran into a `404`, only login and logout worked. That
had been the case since `f1d0771` and was documented in [FRONTEND.md](FRONTEND.md) as a
leftover instead of being fixed.

- **Replaced:** `services/api.js` (one method per route in
  [Router.php](src/Presentation/Router/Router.php)), `router/index.js`, `App.vue` and all
  nine views. The eight prediction/score/game views were deleted.
- **New:** one view each for B-01 through B-14 as well as OPS-01/03/04 — five read-only
  participant views, five admin views. The view → endpoint mapping is in
  [FRONTEND.md](FRONTEND.md).
- **Kept:** `stores/auth.js` and `services/keycloak.js`. The login was the only thing that
  still worked beforehand, and it is domain-neutral.

**The idempotency key is now used rather than merely existing.** `useCommand` keeps the key
exactly when *no* response came back — then and only then is it unclear whether the server
wrote, and a retry with the same key gets the stored result instead of a second booking. As
soon as any status comes back the key is used up: a failed key stays taken on the server
side, and reusing it after a `400` would turn a fixable input error permanently into a `409`.

**Two things the interface makes visible instead of hiding:**

- A token without a `participant_id` claim gets a note in the participant views, not an
  empty page. `Authorization::requireSelf()` does not let an administrator through there
  either — that is deliberate, not a bug.
- A `404` in a read view is an empty state, not an error: "no row is stored for this period"
  is an answer.

A `503` deliberately does **not** lead to the login: Keycloak is unreachable then, and
sending the user there would mean sending them to the service we currently know is not
answering. Only a `401` throws them to the login.

Checked through `docker-compose build frontend` (Vite build, 119 modules, clean). The SPA
still has **no automated tests**, and `npm run lint` is not runnable without an ESLint
configuration — both are listed in [FRONTEND.md](FRONTEND.md) under "Open points".

---

## A working guide for agents (2026-07-29, `de9215b`)

[AGENTS.md](AGENTS.md) as the tool-neutral project guide, [CLAUDE.md](CLAUDE.md) for what
comes on top in this working environment. Contains the status table of which documents were
caught up after the change of course and which were not.

---

## The token signature is verified (2026-07-29, `9378be8`)

**Before this the application read the claims and believed them.** Anyone could issue
themselves a `participant_id` and the role `admin`; B-15 through B-17 were decoration.

- `TokenVerifier` checks `alg` against an allowlist, the signature against the public key
  from the realm's JWKS, `exp`/`nbf`/`iat` with leeway, `iss` verbatim and optionally `aud`
- `JwkSet` builds RSA keys as PEM, `KeycloakKeys` fetches and caches the key set (PSR-16)
  and handles rotation, `StaticKeys` serves deployments without network access
- The allowlist **can only contain asymmetric algorithms** – an `HS256` in the configuration
  shows up at startup rather than on the request that would have been forged with it
- An unreachable Keycloak answers **503, not 401**

The key always comes from the key set, never from the token. An unknown `kid` triggers
exactly one throttled refetch. Details in [KEYCLOAK.md](KEYCLOAK.md).

---

## The operations layer (2026-07-28, `b545ec0`)

OPS-01 through OPS-04: command log, idempotency, audit trail, projections.

- `command_log` with a unique key on the `Idempotency-Key`. The key is claimed **before**
  the command runs – checking first and executing afterwards would leave a window in which
  two parallel retries both get through
- `GET /commands/{commandId}`, `GET /admin/audit/{type}/{id}`, `GET /admin/projections`,
  `POST /admin/projections/{name}/rebuild`
- Seven projectors, one per read model, plus `ProjectionManager`
- `ProjectionRebuildTest` plays a whole tipp year through, rebuilds from the event store and
  compares all 13 read-model tables row by row

A rebuild is deliberately **not** a command: it changes no domain state and does not belong
in the command history.

---

## The base version over HTTP (2026-07-28, `bd83a0d`)

The `Kernel` takes over what used to sit in `public/index.php`: routing, authentication, the
role check, error mapping. `index.php` is now only the bridge to the PHP globals – which
makes the whole chain testable without a web server.

- `ErrorMapper` as the only place that knows HTTP codes; handlers throw domain exceptions
- `Authorization::requireSelf()` compares the identity **from the token** with the path, and
  does so before the query – otherwise a `404` would already reveal that nothing exists for
  someone else's participant
- `Input` and `Support\Row` check `mixed` from the request and the database in one place
  each, instead of casting it everywhere

---

## Commands and queries for B-01 through B-14 (2026-07-28, `444d918`)

Nine command handlers and ten query handlers, plus the nine controllers. Handlers know
nothing about HTTP; commands answer with `202`, queries with `200`.

`WinningsDistribution` lives in the domain service because two callers need the same
calculation: the command handler when recording the winnings, and the `DrawProjector` on a
rebuild. `EvenSplit` divides money in whole cents and puts the remainder onto the first share
– dividing in floating point and rounding per share destroys money.

---

## Repositories for the lotto aggregates (2026-07-28, `7f4e638`)

Seven repositories on the shared base `EventSourcedRepository`.

- Appending and writing the projection in **one** transaction. Otherwise a row rejected by
  the unique key would leave an event in the store that describes no row
- New aggregates with a plain `INSERT`, loaded ones with an `UPDATE` – no
  `ON DUPLICATE KEY UPDATE`, which would silently overwrite a second bet row for the same
  period instead of raising the `409`
- SQLSTATE 23000 becomes `DuplicateEntryException`: a rejected unique key is a business rule
  saying no, not a database error

---

## A configurable bet period (2026-07-28, `c554a18`)

The fixed "one row per tipp year" becomes the **bet period** (`BetPeriod`): a freely chosen,
non-overlapping window within the tipp year. The unique key moves from
`(participant_id, tipp_year_id)` to `(participant_id, bet_period_id)`.

The period length is thereby a configuration, not an assumption in code. The edge case "one
period = the whole tipp year" reproduces exactly the previous behaviour.

---

## Schema and domain onto the lotto model (2026-07-28, `5f8f9ea`)

Seven aggregates (`TippYear`, `BetPeriod`, `BetRow`, `Ticket`, `Draw`, `Fee`,
`Participant`), 14 events, new value objects (`LottoNumbers`, `Superzahl`, `DateRange`,
`EvenSplit`, `WinningClass`, `TippYearStatus`).

New tables: `tipp_year`, `membership`, `bet_period`, `bet_row`, `ticket`, `ticket_row`,
`draw`, `ticket_draw_result`, `ticket_row_match`, `payout`, `payout_share`. The sport tables
are ready as [database/schema-e2-sports.sql](database/schema-e2-sports.sql) for E2.

---

## The change of course to the lottery syndicate (2026-07-27, `f1d0771`)

**The domain had been misunderstood.** The project is not a general sports-betting game but
the administration of a Lotto 6 aus 49 syndicate. The commit moves the model, the stories and
the API specification over, and tiers everything into a base version plus two expansion
stages (E1 self-service, E2 sports betting).

| Previously | Becomes |
|---|---|
| `BettingGame` | `TippYear` |
| `GameParticipation` | `Membership` |
| `Prediction` | `BetRow` – no `event_id`, a `bet_period_id` instead; six numbers rather than free JSON |
| `Event` | `Draw` – no betting deadline, because betting does not happen per draw |
| `Result` | merges into `Draw` |
| `ParticipantScore` | `TicketRowMatch` + `PayoutShare` |

New and without a counterpart in the old model: `Ticket`, `TicketRow`, `TicketDrawResult`,
`TicketRowMatch`, `Payout`, `PayoutShare`.

**Went along with it:** the `demo/` directory (a runnable read-only demo for predictions and
results) was removed; the accompanying `DEMO.md` then described a directory that no longer
existed for nearly two weeks, and was deleted with the documentation update of 2026-07-29.
The old OpenAPI specification is ready as
[betting_game_api_e2_sports.yaml](betting_game_api_e2_sports.yaml) for E2.

**Did not go along with it:** [frontend/](frontend/) kept serving predictions, scores and
games, and no longer matched any endpoint. Caught up on 2026-07-29, see the entry above.

---

## Keycloak integration

**New:** OAuth2/OIDC authentication through Keycloak 23.

- Two new containers: `keycloak` (port 8090) and `keycloak-db` (PostgreSQL 16)
- The realm `betting-game` is imported automatically at startup from
  `keycloak/realm-export.json` – 3 demo users, 2 clients, the roles `user`/`admin`, the
  custom claim `participant_id`
- Backend: `Infrastructure/Auth/KeycloakService.php` and `AuthMiddleware.php`, registered in
  the DI container
- Frontend: `services/keycloak.js`, a reworked auth store, the Keycloak login,
  `public/silent-check-sso.html`, a new `.env`
- The configuration in `config/config.php` and `.env.example` was extended

**Open at the time:** `AuthMiddleware` was not yet called by `public/index.php`; a token
simulation ran there. Done with `bd83a0d` (kernel) and `9378be8` (signature verification).

---

## PSR standards

**New:** PSR-3 (logging), PSR-11 (container), PSR-16 (cache) – in addition to the already
present PSR-4 and PSR-12.

- `Infrastructure/Logging/LoggerFactory.php` – four Monolog loggers (app, event store,
  error, CQRS)
- `Infrastructure/DI/PsrContainer.php` – a PSR-11 adapter around PHP-DI
- `Infrastructure/Cache/FileCache.php` and `RedisCache.php` – PSR-16 with TTL support
- 4 new dependencies: `psr/log`, `psr/container`, `psr/simple-cache`, `monolog/monolog`
- A new test: `tests/Unit/Infrastructure/FileCacheTest.php`

**Open:** the application logic still uses neither. The only users in production are
`KeycloakKeys` (the cache for the JWKS, since `9378be8`) and `AuthMiddleware` (the logger).
Details in [PSR.md](PSR.md).

---

## The Vue.js frontend

**New:** a single-page application for the API.

- 6 views (login, predictions list/new/edit, scores, games), later extended by 3 admin views
- A Pinia auth store, an axios API client with interceptors, Vue Router with guards
- Its own container in the stack: a production build via Vite, served by nginx on port 3000

Details in [FRONTEND.md](FRONTEND.md).

---

## One class per file

**Rebuild:** 12 collection files with several classes each were split into 48 individual
files. No functional changes, no breaking changes – namespaces and API stayed identical.

| Before | After |
|--------|-------|
| `ValueObjects.php` | 6 files in `Domain/ValueObject/` |
| `Exceptions.php` | 8 files in `Domain/Exception/` |
| `PredictionEvents.php` | 3 files + `DomainEvent.php` |
| `RepositoryInterfaces.php` | 4 files in `Domain/Repository/` |
| `Commands.php` | 5 files in `Application/Command/` |
| `CommandHandlers.php` | 2 handler files |
| `Queries.php` | 6 files in `Application/Query/` |
| `QueryHandlers.php` | 4 files (handlers + read-model interfaces) |
| `Repositories.php` | 3 files in `Infrastructure/Persistence/` |
| `ReadModelRepositories.php` | 2 files |
| `Controllers.php` | 2 controller files |
| `HttpHelpers.php` | `Request.php`, `JsonResponse.php` |

**The benefit:** an exact PSR-4 mapping, more precise diffs, faster IDE navigation, fewer
merge conflicts.

**Imports** changed from `use …\ValueObject\ValueObjects;` (access through
`ValueObjects\ParticipantId`) to individual imports per class.

Since then the codebase has grown to **153 files** under `src/`. Two exceptions to the rule
still stand: `PsrContainer.php` and `FileCache.php` each additionally contain their exception
classes.

---

## Docker stack v2.0 – modernisation

**Replaced:** Apache with mod_php → Caddy 2.7 + PHP-FPM 8.3 (Alpine).
**Updated:** MariaDB 10.11 → 11.3.

New files:

```
docker/
├── Dockerfile.php          # custom PHP-FPM image
├── Caddyfile               # Caddy configuration
├── php-fpm.conf            # pool settings
├── php.ini                 # runtime settings
├── nginx.conf.example      # the nginx alternative
└── apache.conf             # the Apache example (legacy)
.dockerignore
```

Changes to `docker-compose.yml`: web server and PHP in separate services, a network of its
own, persistent volumes for Caddy, optimised MariaDB parameters.
New make targets: `logs-php`, `logs-caddy`, `logs-db`, `build`, `fresh`, shell access.

**Why Caddy:** automatic HTTPS, HTTP/2 and HTTP/3, simpler configuration, built-in
compression (gzip, zstd), zero-downtime reloads.
**Why PHP-FPM:** a considerably smaller image, better process management, independent
scaling, a preconfigured OPcache.

Rough figures from the switch (not measured): image ~400 MB → ~50 MB, RAM ~150 MB → ~80 MB,
latency ~8 ms → ~5 ms.

**No breaking changes** – the API stayed unchanged, and so did the URLs
(API `:8080`, PHPMyAdmin `:8081`).

**Security:** `expose_php` disabled, an Alpine base, security headers in the Caddyfile,
network isolation, PHP-FPM workers run as `www-data` (the master process runs as root, as is
usual with PHP-FPM).

---

## Docker stack – configuration errors fixed

Two misnamed directives prevented startup:

| File | Error | Cause |
|------|-------|-------|
| `docker/Caddyfile` | `unrecognized subdirective split_path` | in Caddy 2 the directive is called `split`, not `split_path` – and is not needed at all for the standard front controller |
| `docker/php-fpm.conf` | `unknown entry 'process_priority'` | the correct form is `process.priority` (with a dot) |

Additionally removed: `request_slowlog_timeout`, `slowlog`, `listen.backlog`, `access.log`,
`access.format` – all valid directives, but ones that assume a writable log directory, which
the Alpine image lacks.

As a fallback, `docker/Caddyfile.minimal`, `docker/Caddyfile.alternative`,
`docker/php-fpm.conf.minimal` came into being, along with the scripts `fix-caddy.sh` and
`fix-php-fpm.sh` (make targets `fix-caddy`, `fix-php-fpm`, `fix-all`).

Diagnosis and fallbacks: [DOCKER.md](DOCKER.md), section "Troubleshooting".

---

## Planned

**Gaps in the base version**

- [ ] A route and command for the tipp year's lifecycle (`start`, `close`) — today only
      reachable from tests, see [ARCHITECTURE.md](ARCHITECTURE.md), section 9
- [ ] An endpoint for creating a participant (self-registration is E1-01)

**Technical**

- [ ] `LoggerInterface` into the command handlers
- [ ] Cache the read models (PSR-16 exists), including invalidation
- [ ] A Redis service in `docker-compose.yml`
- [ ] Health checks in `docker-compose.yml`, a multi-stage Docker build
- [ ] Event publishing: `event_publisher` is written but drained by nobody
- [ ] Prometheus metrics, tracing, rate limiting

**Domain**

- [ ] Expansion stage E1 (self-service), expansion stage E2 (sports betting)
- [ ] Connect the frontend to the current API, or remove it
