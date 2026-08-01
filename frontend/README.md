# Frontend – lottery syndicate

A Vue 3 SPA for the **Lotto 6 aus 49 syndicate**. It drives the endpoints from
[`../betting_game_api.yaml`](../betting_game_api.yaml); the mapping view → endpoint is in
[`../FRONTEND.md`](../FRONTEND.md), the domain in
[`../USER_STORIES.md`](../USER_STORIES.md), the login in
[`../KEYCLOAK.md`](../KEYCLOAK.md).

**Expansion stage base:** participants only read, the administrator writes everything.
The SPA mirrors exactly that — the participant views have not a single submit button.

The two roles get **two separate areas**: the participant views under a light top bar, and
everything under `/admin` behind its own layout with a dark bar and a sidebar. A single
`Verwaltung` link leads across, and it is only shown to an admin.

> The user interface is German, deliberately: it is the language of the syndicate using it.
> Everything else in this repository — code, comments, documentation — is English.

## Prerequisites

- Node.js 24 (active LTS; 18 and 20 are end-of-life)
- API on `http://localhost:8080` (`curl http://localhost:8080/health`)
- Keycloak on `http://localhost:8090`, realm `betting-game`

## Development

```bash
cd frontend
npm install
npm run dev        # http://localhost:3000
npm run build      # output into dist/
npm run lint       # checks, changes nothing
npm run lint:fix   # fixes whatever can be fixed automatically
npm test           # Vitest, single run
npm run test:watch
npm run test:e2e   # Playwright, needs the running stack (docker-compose up -d)
```

Without a local Node both run in the container:

```bash
docker run --rm -v "$PWD:/app" -w /app node:24-alpine sh -c "npm install && npm run lint"
```

Rule set: `eslint:recommended` + `plugin:vue/vue3-recommended` (see
[`eslint.config.js`](eslint.config.js)). `vue3-recommended` is the strictest of the three
Vue presets; besides the error rules it also carries the formatting and ordering rules.
The codebase is clean — **keep it that way**.

If the frontend container from `docker-compose.yml` runs in parallel it occupies port 3000 —
`docker-compose stop frontend` first.

## Project structure

```
frontend/
├── src/
│   ├── layouts/
│   │   ├── ParticipantLayout.vue      # light top bar, the read-only views
│   │   └── AdminLayout.vue            # dark bar + sidebar, everything /admin
│   ├── views/
│   │   ├── LoginView.vue              # Keycloak login
│   │   ├── BetRowView.vue             # B-01 own bet row
│   │   ├── MembershipsView.vue        # B-02 own memberships
│   │   ├── FeesView.vue               # B-03 own fees
│   │   ├── PayoutShareView.vue        # B-04 own payout share
│   │   ├── DrawsView.vue              # B-05 draws of the tipp year
│   │   ├── AdminTippYearsView.vue     # B-10 through B-14
│   │   ├── AdminBetRowsView.vue       # B-06 assign a row
│   │   ├── AdminDrawsView.vue         # B-08, B-09
│   │   ├── AdminFeesView.vue          # B-07 record fees
│   │   └── AdminOperationsView.vue    # OPS-01, OPS-03, OPS-04
│   ├── components/
│   │   ├── CommandFeedback.vue        # a command's response including commandId
│   │   └── ParticipantScope.vue       # note shown when the token lacks participant_id
│   ├── composables/useCommand.js      # command/query state, idempotency key
│   ├── services/
│   │   ├── api.js                     # axios client, one method per route
│   │   ├── errors.js                  # error message out of the API response
│   │   └── keycloak.js                # keycloak-js wrapper
│   ├── stores/auth.js                 # Pinia auth store
│   ├── support/format.js              # money, dates, lotto numbers, status labels
│   ├── assets/app.css                 # shared design system
│   ├── router/index.js
│   ├── App.vue
│   └── main.js
├── tests/unit/                     # Vitest, mirroring the src/ structure
├── public/silent-check-sso.html
├── .env                               # Keycloak and API URLs
├── vite.config.js                     # including the Vitest configuration (test:)
├── eslint.config.js                   # flat config (ESLint 9+)
├── Dockerfile                         # build + nginx
└── nginx.conf
```

## Login

Login through Keycloak (OIDC with PKCE). The token lives in the adapter's memory, **not**
in localStorage. `participant_id`, username and roles come as claims out of the JWT.

Demo users from `keycloak/realm-export.json`:

```
admin    / admin123   (roles user + admin, participant_id 1)
testuser / test123    (role  user,         participant_id 2)
john.doe / password   (role  user,         participant_id 3)
```

Without a `participant_id` in the token the participant views show a note instead of data.
That is not a gap: the API derives identity from the token and does not let an administrator
through there either — they have their own endpoints.

## API integration

```
Vue component → api.js (axios) → request interceptor (token) →
proxy /api → backend → response interceptor (401) → component
```

The proxy is configured in `vite.config.js` (`/api` → `http://localhost:8080`, the prefix is
stripped); in the container `nginx.conf` takes over.

`api.js` has exactly one method per route in
[`../src/Presentation/Router/Router.php`](../src/Presentation/Router/Router.php) —
participant routes directly, admin routes under `api.admin.*`.

### Commands and the idempotency key

Writing calls take an `Idempotency-Key`. It is issued in `composables/useCommand.js`, and
deliberately not anew on every click:

- If **no response** comes back (timeout, network), the key stays. Clicking again repeats
  the same command; the API answers with the stored result and `Idempotent-Replay: true`
  instead of recording a second time.
- If **any status** comes back, the key is used up. A key whose first attempt failed stays
  taken on the server side — reusing it after a `400` would turn a fixable input error
  permanently into a `409`.

Responses to commands are `202` with a `commandId`. It is displayed and links to
**Operations → processing state**; that is the only way to look up later what an attempt
produced.

### Honest about asynchrony

The API describes commands as asynchronous, the implementation writes synchronously: whoever
holds the `202` already sees the result in the read models. That is why the admin views
reload immediately after a command — there is no race here.

## Error handling

`services/errors.js` shows the `message` from the API response, not the one from axios:
"Request failed with status code 409" does not say which rule said no.

Two status codes are treated differently:

- `401` — the token was rejected, the interceptor sends the user to the login.
- `503` — Keycloak is unreachable. The call is repeatable and the token stays valid; sending
  the user to the login here would mean sending them to precisely the service we know is not
  answering right now.

In the read views a `404` is a statement ("no row is stored for this period") and is shown
as an empty state, not as an error.

## Deployment

```bash
docker-compose build frontend && docker-compose up -d
# frontend :3000 | API :8080 | PHPMyAdmin :8081 | Keycloak :8090
```

Static hosting: `npm run build`, then serve `dist/`. With your own nginx, additionally
configure `try_files $uri $uri/ /index.html;` and an `/api/` proxy.

## Troubleshooting

**API calls fail** — is the backend running? `curl http://localhost:8080/health`.
Check the proxy in `vite.config.js`; Caddy sets the CORS headers.

**Login fails** — is Keycloak reachable (`curl http://localhost:8090/realms/betting-game`)?
Check the values in `.env`; the redirect URI of the client `betting-game-frontend` has to
match the URL being called. After changes to `.env`, restart the dev server.

**Participant views stay empty** — does the token carry a `participant_id` claim? It comes
from the user attribute in the realm.

**Everything is empty, but without an error** — most likely no data has been created.
[`../QUICKSTART.md`](../QUICKSTART.md) plays a tipp year through by hand.

**Build errors** — `rm -rf node_modules dist && npm install && npm run build`

## Stack

Vue 3.5 (composition API, `<script setup>`), Vue Router 5.2, Pinia 4.0, axios 1.19,
keycloak-js 26, Vite 8.

## Open points

- Vitest (`tests/unit/`, 61 tests) and Playwright (`tests/e2e/`, 10 tests against the real
  stack) are green. See [FRONTEND.md](../FRONTEND.md), section "Testing".
- No TypeScript.
- Linking a participant to their Keycloak account is still manual: `POST /admin/participants`
  hands out a `resourceId`, and that number has to be entered as the user's `participant_id`
  attribute in the realm by hand. Closing that means managing accounts — E1-01.
