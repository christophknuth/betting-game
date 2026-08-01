# Frontend – Vue.js 3 SPA

The user interface for the **Lotto 6 aus 49 syndicate**. It drives the endpoints from
[betting_game_api.yaml](betting_game_api.yaml); the authority on the domain is
[USER_STORIES.md](USER_STORIES.md), on the routes
[Router.php](src/Presentation/Router/Router.php).

Setup notes are in [`frontend/README.md`](frontend/README.md), the auth details in
[KEYCLOAK.md](KEYCLOAK.md).

> **Note on the interface language.** The SPA's user-facing text is German, deliberately:
> that is the language of the syndicate using it. Everything else in this repository —
> code, comments, documentation — is English.

> **Prehistory.** Until 2026-07-29 this documented an SPA of the old sports-betting game
> (predictions, scores, games). Those endpoints have not existed since the change of course
> to the lottery (`f1d0771`); every domain request ran into a `404`. The SPA has been moved
> onto the current API — views, router and API client were replaced, auth store and Keycloak
> wrapper stayed.

## Overview

| Metric | Value |
|--------|-------|
| Views | 12 (1 login, 5 participant, 6 admin) |
| Components | 2 shared + `App.vue` |
| Routes | 14 (incl. the redirect `/` → `/bet-row` and a catch-all) |
| Services | 3 (API client, error messages, Keycloak wrapper) |
| Other | 1 composable, 1 formatting module, 1 auth store, 1 stylesheet |

**Stack:** Vue 3.5 (composition API, `<script setup>`), Vue Router 5.2, Pinia 4.0,
axios 1.19, keycloak-js 26, Vite 8.

## Expansion stage base: what the interface may show

Participants only read, the administrator writes everything. The SPA mirrors that — the five
participant views have not a single submit button, because there is no endpoint for one.
Self-service is E1 and not implemented.

## Views and routes

| View | Route | Endpoint | Story |
|------|-------|----------|-------|
| LoginView | `/login` | — (Keycloak) | |
| BetRowView | `/bet-row` | `GET /participants/{id}/bet-row` | B-01 |
| MembershipsView | `/memberships` | `GET /participants/{id}/memberships` | B-02 |
| FeesView | `/fees` | `GET /participants/{id}/fees` | B-03 |
| PayoutShareView | `/payout-share` | `GET /participants/{id}/payout-share` | B-04 |
| DrawsView | `/draws` | `GET /tipp-years/{id}/draws` | B-05 |
| AdminParticipantsView | `/admin/participants` | `GET`/`POST /admin/participants` | B-21 |
| AdminBetRowsView | `/admin/bet-rows` | `PUT /admin/participants/{id}/bet-row` | B-06 |
| AdminFeesView | `/admin/fees` | `GET /admin/fees`, `PUT /admin/fees/{id}/payment` | B-07 |
| AdminDrawsView | `/admin/draws` | `POST /admin/draws`, `PUT /admin/draws/{id}/winnings` | B-08, B-09 |
| AdminTippYearsView | `/admin/tipp-years` | tipp years, status, periods, members, tickets, distribution | B-10 – B-14, B-18 |
| AdminOperationsView | `/admin/operations` | `GET /commands/{id}`, `GET /admin/audit/…`, `GET/POST /admin/projections…` | OPS-01, OPS-03, OPS-04 |

`/` redirects to `/bet-row`. A catch-all route catches unknown paths — among them all URLs
of the old SPA, which otherwise ended as a white page.

Routes with `requiresAuth` demand a login, `/admin/*` additionally `requiresAdmin`.
The guard only hides the entrance; the API checks the role itself on every admin route, and
that is where the decision is made.

**The guard is `async` and waits for `authStore.ready()`.** Vue Router starts its first
navigation already inside `app.use(router)` — that is, before `main.js` has awaited the
Keycloak start. Without that wait the guard judged a logged-in user to be anonymous, sent
the requested route to `/login`, and by the time the session came back the destination was
lost: every deep link and every reload of a protected page ended up on `/bet-row`.
`ready()` returns the same memoised promise the app start awaits.

```javascript
router.beforeEach(async (to, from, next) => {
  const authStore = useAuthStore()

  await authStore.ready()

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    next('/login')
  } else if (to.meta.requiresAdmin && !authStore.isAdmin()) {
    next(HOME)
  } else if (to.path === '/login' && authStore.isAuthenticated) {
    next(HOME)
  } else {
    next()
  }
})
```

### The `participant_id` claim

The four participant views and `DrawsView` need the `participant_id` claim from the token.
If it is missing, `ParticipantScope.vue` shows a note instead of data.

That is deliberate and not a gap: the API derives identity from the token, never from the
path, and `Authorization::requireSelf()` does not let an administrator through there either.
An admin without a `participant_id` of their own sees these views empty — their view of
other people's data is the admin endpoints.

## API integration

```
Vue component → api.js (axios) → request interceptor (token) →
proxy /api → backend → response interceptor (401) → component
```

The proxy is in `vite.config.js`: `/api` → `http://localhost:8080`, the prefix is stripped.
In the container `nginx.conf` takes over. Dev server on port 3000.

`services/api.js` has exactly one method per route:

```javascript
// Participant, read only
api.getBetRow(participantId, betPeriodId)
api.getMemberships(participantId, tippYearId)
api.getFees(participantId, { tippYearId, paymentStatus })
api.getPayoutShare(participantId, tippYearId)
api.getDraws(tippYearId, { status, withWinningsOnly })
api.getCommandStatus(commandId)

// Administrator – under api.admin.*, commands with an idempotency key
api.admin.assignBetRow(participantId, data, key)
api.admin.getFees(filters)
api.admin.recordFeePayment(feeId, data, key)
api.admin.recordDraw(data, key)
api.admin.recordDrawWinnings(drawId, data, key)
api.admin.getTippYears(status)
api.admin.createTippYear(data, key)
api.admin.getBetPeriods(tippYearId)
api.admin.createBetPeriod(tippYearId, data, key)
api.admin.addMember(tippYearId, data, key)
api.admin.submitTicket(tippYearId, data, key)
api.admin.distributePayout(tippYearId, data, key)
api.admin.getAuditTrail(aggregateType, aggregateId)
api.admin.getProjections()
api.admin.rebuildProjection(name)
```

### Commands and the idempotency key

The key is not issued in the API client but in `composables/useCommand.js` — only the caller
knows whether a second click is a repetition of the same intent or a new command:

- **No response** (timeout, network): the key stays. Clicking again repeats the same
  command, and the API returns the stored result with `Idempotent-Replay: true` instead of
  recording a second time. That is exactly what the header exists for.
- **Any status**: the key is used up. A key whose first attempt failed stays taken on the
  server side; reusing it after a `400` would turn a fixable input error permanently into a
  `409`.

`AdminDrawsView` therefore keeps **one** command state **per draw**: a key left over from
one row must not answer the booking of the next row.

The `commandId` from the `202` is displayed and links to **Operations → processing state**
(`GET /commands/{id}`). That endpoint is deliberately not admin-protected.

### Honest about asynchrony

The API describes commands as asynchronous, the implementation writes synchronously: by the
time the `202` arrives, event store and read models are already up to date. The admin views
reload immediately afterwards — not a race, but the consequence of that.

## Error handling

`services/errors.js` shows the `message` from the API response rather than the axios one:
"Request failed with status code 409" does not say which business rule said no.

| Status | Behaviour |
|---|---|
| `401` | the interceptor redirects to the Keycloak login |
| `403` | the API's message (e.g. "You may only access your own data") |
| `404` | in read views an **empty state**, not an error — "no row is stored for this period" is a statement |
| `409` | the message of the rejected business rule |
| `503` | a note that the call is repeatable — **no** redirect to the login |

The distinction `401` / `503` is why the interceptor only reacts to `401`: a `503` means
Keycloak is not answering right now. Sending the user there would mean sending them to
precisely the service we know is unreachable.

## Layout of the sources

```
frontend/src/
├── views/                 11 pages, one per view in the table above
├── components/
│   ├── CommandFeedback.vue    a command's response including commandId
│   └── ParticipantScope.vue   note shown when the token lacks participant_id
├── composables/useCommand.js  useCommand (idempotency key) and useQuery
├── services/
│   ├── api.js                 one method per route
│   ├── errors.js              error message out of the API response
│   └── keycloak.js            keycloak-js wrapper
├── stores/auth.js             Pinia auth store
├── support/format.js          money, dates, lotto numbers, status labels
├── assets/app.css             shared design system
├── router/index.js
├── App.vue
└── main.js
```

## Authentication

Login runs entirely through Keycloak (OAuth2/OIDC with PKCE). Tokens live exclusively in the
memory of the keycloak-js adapter, **not** in localStorage.

```javascript
await authStore.initKeycloak()   // at app start (main.js)
await authStore.login()          // redirect to the Keycloak login page
await authStore.logout()         // Keycloak logout + clear local state

keycloakService.onTokenExpired(() => keycloakService.updateToken(30))
```

Demo users and realm details: [KEYCLOAK.md](KEYCLOAK.md).

## Design system

Shared in `src/assets/app.css`, not as scoped styles per component — the old SPA carried the
same card, button and badge rules nine times, and every colour change had to be made nine
times.

```css
--blue:     #2563eb;   /* primary actions */
--green:    #10b981;   /* winnings, success */
--yellow:   #f59e0b;   /* outstanding items, bonus number */
--red:      #ef4444;   /* errors, irreversible actions */
--gray-900: #1f2937;   /* headings */
--gray-600: #6b7280;   /* body text */
--gray-300: #d1d5db;   /* borders */
--gray-100: #f3f4f6;   /* surfaces */
```

**Building blocks:** `.card` / `.card-grid`, `.facts` (definition list), `table.data`,
`.numbers .ball` (lotto balls, bonus number in yellow), `.badge` with status classes,
`.field` / `.field-row` / `.field-inline`, `.btn-primary|secondary|danger|link`, `.state`
(`loading`, `empty`, `error`, `success`, `note`).

**Responsive:** mobile first, grid with `repeat(auto-fill, minmax(320px, 1fr))`, tables in
`.table-wrap` with horizontal scroll.

## Development

```bash
cd frontend
npm install
npm run dev        # http://localhost:3000 (the backend has to run on :8080)
npm run build      # output into dist/
npm run lint       # checks, changes nothing
npm run lint:fix   # fixes what can be fixed automatically
```

### Rule set

`eslint:recommended` + `plugin:vue/vue3-recommended`, configured in
[`frontend/eslint.config.js`](frontend/eslint.config.js). `vue3-recommended` is the strictest
of the three Vue presets — it stacks *essential* (real errors),
*strongly-recommended* (readability) and *recommended* (ordering and naming).
The Vue documentation recommends exactly this combination itself; anyone who knows it does
not have to learn house rules here.

One exception is configured: `vue/multi-word-component-names` permits `App` — the one
component that sensibly does not carry a second word.

The codebase is **clean** — keep it that way. Without a local Node the check runs in the
container:

```bash
docker run --rm -v "$PWD/frontend:/app" -w /app node:24-alpine \
  sh -c "npm install && npm run lint"
```

The formatting rules (`max-attributes-per-line`,
`singleline-html-element-content-newline`) are deliberately **not** switched off, even
though they make the templates longer: they replace the formatter this project does not
have. Anyone switching them off needs one — otherwise the formatting drifts apart again.

If the frontend container runs in parallel it occupies port 3000 — `docker-compose stop
frontend` first.

### Deployment

```bash
docker-compose build frontend && docker-compose up -d
# frontend :3000 | API :8080 | PHPMyAdmin :8081 | Keycloak :8090
```

Static hosting (Netlify, Vercel): `npm run build`, then deploy `dist/`. With a manual nginx,
additionally `try_files $uri $uri/ /index.html;` and an `/api/` proxy.

## Testing

**Vitest** covers composables, stores, services and the router guard —
[`tests/unit/`](frontend/tests/unit/), organised like the backend tests in `tests/Unit`.
Every file is anchored to a concrete user story or acceptance criterion, not to the
implementation:

| File | Checks |
|---|---|
| `composables/useCommand.spec.js` | OPS-02: the idempotency key is retained on a response-less request (a retry repeats the same command) and dropped on any response — success as well as error; `useQuery` falls back to the initial value on errors (B-01: a 404 is an empty state) |
| `services/errors.spec.js` | `apiMessage` for 401 (the iss-claim hint), 503 (repeatable), the passed-through API message, an unreachable API |
| `support/format.spec.js` | `formatAmount(null)` ≠ `formatAmount(0)` (B-04: the share is `null` until the distribution is recorded); `parseNumbers` against B-06 (exactly six distinct numbers 1–49, ascending) |
| `stores/auth.spec.js` | `isAdmin()`/`hasRole()` (B-17), the `displayName` fallback, `logout()` clears local state even when the Keycloak logout itself fails |
| `router/guard.spec.js` | `requiresAuth`/`requiresAdmin` (B-15 through B-17): anonymous → `/login`, participant → no entry to `/admin/*` |
| `components/ParticipantScope.spec.js` | A missing `participant_id` claim shows the note instead of the participant views |

```bash
npm test          # single run
npm run test:watch
```

Without a local Node this runs in the same container as lint:

```bash
docker run --rm -v "$PWD/frontend:/app" -w /app node:24-alpine sh -c "npm install && npm test"
```

**Playwright** covers the pass against the real, running stack —
[`tests/e2e/`](frontend/tests/e2e/): a real Keycloak login, a real API, real read models.

| File | Checks |
|---|---|
| `auth.spec.js` | A real Keycloak login (B-15), the admin area unreachable for participants (B-17), logout |
| `participant-views.spec.js` | B-01, B-03, B-05 with real, seeded data for `testuser` |
| `admin-fee-payment.spec.js` | B-07 as a real write through the interface, not just a read |
| `admin-participants.spec.js` | B-21: creating a participant, and that they then turn up in the dropdowns |

```bash
docker-compose up -d          # the full stack has to run, .env bakes localhost:* in
npm run test:e2e
```

Without a local Node, in the official Playwright image. `--network host` so that `localhost`
in the browser hits the same ports as on the development machine; the artefacts land
deliberately **inside the container**, otherwise they end up owned by a UID the host can no
longer clean up:

```bash
docker run --rm --network host -e PLAYWRIGHT_OUTPUT_DIR=/tmp/pw-results \
  -v "$PWD:/repo" -w /repo/frontend \
  mcr.microsoft.com/playwright:v1.62.1-noble sh -c "npm run test:e2e"
```

### What `global-setup.js` prepares

It seeds a complete tipp year through the real command handlers — the same sequence as in
[QUICKSTART.md](QUICKSTART.md), only automated instead of with `curl`. Three peculiarities
that are not obvious, and without which the suite breaks on the **second** run:

- **Every run gets its own calendar year.** Tipp years must not overlap
  (`TippYear::assertNoOverlap`), so the setup picks the first year after the latest existing
  `endDate`. A fixed period would allow exactly one run and nothing but `409` afterwards.
- **The year is left `closed`.** B-18 permits at most one running tipp year; a year left
  running blocks the next run.
- **Every test gets its own fee.** The admin test records the administrator's, the
  participant test reads `testuser`'s — otherwise the outcome would depend on the order the
  files happen to run in.

Creating participants has no endpoint (self-registration is E1-01), which is why that sits
alongside as [`database/seed-demo-participants.sql`](database/seed-demo-participants.sql).
The setup loads it through `docker-compose`; if the Compose CLI is unreachable (in the
container above, for instance) it warns and assumes the rows are already there:

```bash
docker-compose exec -T db mariadb -uroot -psecret betting_game < database/seed-demo-participants.sql
```

### Why the view tests click through the navigation

`page.goto()` works now that the guard waits for the Keycloak start (see "navigation guard"
above) — `auth.spec.js` checks exactly that. The view tests still click through
`navigateTo()` from `fixtures.js`: the same path a user takes, and without a full reload per
test.

A manual checklist for what Playwright does not cover either:

- [ ] Login through Keycloak, logout, redirect to `/login` without a session
- [ ] The session survives a reload (silent SSO)
- [ ] `/admin/*` reachable only with the admin role
- [ ] A token without `participant_id`: the participant views show the note, not an error
- [ ] Create a tipp year → set it to `running` → create a period → add a member →
      assign a row → submit the ticket → record a draw → add the winnings →
      record the fee (the walkthrough from [QUICKSTART.md](QUICKSTART.md))
- [ ] Status dropdown: a second year to `running` → `409`, and the dropdown jumps back to
      the old value instead of leaving a lie on screen
- [ ] A ticket on a tipp year in status `planned`: `409` with a readable message
- [ ] Distribution without the checkbox: the button stays disabled
- [ ] A fee with no numbers in the system: an empty state instead of an error
- [ ] Operations: show the projections, rebuild one, event history of a bet row
- [ ] Responsive layout, loading, empty and error states

## Troubleshooting

**API calls fail** – is the backend running on 8080 (`curl http://localhost:8080/health`)?
Check the proxy in `vite.config.js`; Caddy sets the CORS headers.

**Login does not work** – see [KEYCLOAK.md](KEYCLOAK.md), section "Troubleshooting".

**The participant views show the `participant_id` note** – the token does not carry the
claim. It comes from the user attribute in the realm, see `keycloak/realm-export.json`.

**Everything empty, but without an error** – most likely no data has been created.
[QUICKSTART.md](QUICKSTART.md) plays a tipp year through by hand.

**The frontend container does not start**

```bash
docker-compose build frontend --no-cache
docker-compose logs frontend
```

**Build errors** – `rm -rf node_modules dist && npm install && npm run build`

## Open points

- **Participants see their `participant_id` linked nowhere.** B-21 creates participants, but
  the mapping to the Keycloak user still happens by hand through the realm attribute
  `participant_id`. For as long as the two drift apart, the participant views show someone
  else's data or none at all. Closing that properly means managing accounts — E1.
- **No self-service.** Participants only read; registration, profile and choosing one's own
  row are E1-01 through E1-03.
- Only after that: TypeScript, dark mode, multilingual support.
