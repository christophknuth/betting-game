# Keycloak – authentication

User management and authentication through **Keycloak 26.7** with OAuth2 / OpenID Connect.

> **Status:** fully wired up. [`public/index.php`](public/index.php) hands over to
> [`Kernel`](src/Presentation/Http/Kernel.php), which puts `AuthMiddleware` in front of every
> route that does not explicitly mark itself public. The signature is verified against the
> realm's JWKS endpoint — see
> [TokenVerifier](src/Infrastructure/Auth/TokenVerifier.php).

## Quick start

```bash
docker-compose up -d

# On its first start Keycloak needs 30-60 seconds for the realm import
docker-compose logs -f keycloak    # wait for "Keycloak 26.7.x started", then Ctrl+C

make composer-install
```

> If you only want to test the API, grab a token with the snippet under
> [Testing](#testing) and leave the frontend container out.

Afterwards the frontend runs as a container on port 3000. For development with hot reload,
use the Vite dev server instead:

```bash
docker-compose stop frontend   # frees port 3000, Vite uses the same port
cd frontend && npm install && npm run dev
```

### Test the login

1. Open <http://localhost:3000>
2. Click "Login with Keycloak"
3. Enter the demo credentials (see below)

## Services

| Service | Port | URL | Credentials |
|---------|------|-----|-------------|
| Frontend | 3000 | <http://localhost:3000> | see the demo users |
| Backend API | 8080 | <http://localhost:8080> | JWT token |
| Keycloak | 8090 | <http://localhost:8090> | – |
| Admin console | 8090 | <http://localhost:8090/admin> | admin / admin |

## Preconfigured users

The realm `betting-game` is imported automatically at startup from
[`keycloak/realm-export.json`](keycloak/realm-export.json).

| Username | Password | Roles | `participant_id` |
|----------|----------|-------|------------------|
| `admin` | `admin123` | user, admin | 1 |
| `testuser` | `test123` | user | 2 |
| `john.doe` | `password` | user | 3 |

The claim `participant_id` decides whose data a token may read; the role `admin` unlocks the
write endpoints. Both live in the realm export, not in the application.

## Architecture

```
┌─────────────┐         ┌──────────────┐         ┌──────────────┐
│   Frontend  │────────>│   Keycloak   │<────────│   Backend    │
│   (Vue.js)  │ Login   │   Server     │ Verify  │   (PHP API)  │
└─────────────┘         └──────────────┘         └──────────────┘
      │                        │                         │
      │ JWT Token              │ Public Key              │
      └───────────────────────────────────────────────────┘
```

Login sequence:

1. The user clicks "Login" → redirect to the Keycloak login page
2. Keycloak validates the credentials and issues an authorization code
3. keycloak-js exchanges the code for access/refresh/ID tokens (PKCE)
4. The axios interceptor attaches the access token to every API request
5. The backend validates the signature against the realm's public key

## Components

### Backend

| File | Job |
|------|-----|
| `src/Infrastructure/Auth/TokenVerifier.php` | Signature and claim verification |
| `src/Infrastructure/Auth/JwkSet.php` | Read the JWKS, build RSA keys as PEM |
| `src/Infrastructure/Auth/KeycloakKeys.php` | Fetch and cache the JWKS, rotation |
| `src/Infrastructure/Auth/KeycloakService.php` | Token parsing, `participant_id`, roles |
| `src/Infrastructure/Auth/AuthMiddleware.php` | Request authentication, user context |
| `src/Infrastructure/DI/Container.php` | Registration of the services |

```php
// Throws InvalidTokenException when the token is rejected, and
// KeyUnavailableException when that cannot be decided right now.
$tokenData     = $keycloakService->verifyToken($jwtToken);
$participantId = $keycloakService->getParticipantId($tokenData);
$roles         = $keycloakService->getRoles($tokenData);

if ($keycloakService->hasRole($tokenData, 'admin')) { /* ... */ }
```

```php
// In the router/controller
$authResponse = $authMiddleware->handle($request);
if ($authResponse) {
    return $authResponse;             // 401 Unauthorized
}

$participantId = $request->attribute('participant_id');
$username      = $request->attribute('username');
$roles         = $request->attribute('roles');
```

### Frontend

| File | Job |
|------|-----|
| `frontend/src/services/keycloak.js` | keycloak-js wrapper (init, login, logout, token) |
| `frontend/src/stores/auth.js` | Pinia store with user state and roles |
| `frontend/src/views/LoginView.vue` | Login page |
| `frontend/public/silent-check-sso.html` | Silent SSO check |
| `frontend/.env` | Keycloak and API URLs |

```javascript
const authStore = useAuthStore()

await authStore.initKeycloak()   // at app start (main.js)
await authStore.login()
await authStore.logout()

authStore.isAuthenticated        // computed
authStore.username               // from preferred_username
authStore.participantId          // from the custom claim
authStore.roles                  // from realm_access.roles
authStore.isAdmin()              // hasRole('admin')
```

The axios interceptor fetches the current token, refreshes it where needed and sets
`Authorization: Bearer <token>`. On `401` a redirect to the Keycloak login follows.

## Configuration

**Backend** – [`config/config.php`](config/config.php), values from environment variables:

```php
'keycloak' => [
    'url'                 => 'http://keycloak:8080',   // internal Docker hostname
    'realm'               => 'betting-game',
    'client_id'           => 'betting-game-api',
    'frontend_client_id'  => 'betting-game-frontend',
],
```

**Frontend** – [`frontend/.env`](frontend/.env):

```
VITE_KEYCLOAK_URL=http://localhost:8090
VITE_KEYCLOAK_REALM=betting-game
VITE_KEYCLOAK_CLIENT_ID=betting-game-frontend
VITE_API_URL=http://localhost:8080
```

### Clients

| Client | Type | Standard flow | Direct access grants | Redirect URIs |
|--------|------|---------------|----------------------|---------------|
| `betting-game-frontend` | Public (PKCE) | on | on | `http://localhost:3000/*` |
| `betting-game-api` | Bearer-only | off | off | – |

## Tokens

### Claims

```json
{
  "exp": 1706789012,
  "iat": 1706785412,
  "iss": "http://localhost:8090/realms/betting-game",
  "sub": "f3e2d1c0-b9a8-7654-3210-fedcba987654",
  "preferred_username": "testuser",
  "email": "test@bettinggame.local",
  "participant_id": "2",
  "realm_access": { "roles": ["user"] }
}
```

`participant_id` is mapped from the user attributes into the token – no separate lookup
needed. The mapper hangs **directly off the client** `betting-game-frontend`
(`protocolMappers`), not off a client scope of its own. Why, follows right below.

### One client scope in the realm export deletes the built-in ones

> This trap made the realm unusable between the change of course and 2026-07-29. Anyone
> touching the export has to know about it.

If a realm export contains a **top-level `clientScopes` block**, Keycloak reads it as *the
complete list* of the realm's client scopes — and then does **not** create the built-in ones
(`profile`, `email`, `roles`, `web-origins`, `acr`).

This project's export defined exactly one scope (`participant_id`). The consequences:

- The realm had only `participant_id` and `offline_access`.
- The frontend client's `defaultClientScopes` referred to five scopes that did not exist.
  Keycloak discards such references **silently** — the client ended up with *zero* assigned
  scopes.
- Issued tokens consequently carried neither `participant_id` nor `preferred_username` nor
  `realm_access.roles`.

And with that the entire authorisation was ineffective, without an error appearing anywhere:

| Effect | Consequence |
|---|---|
| no `participant_id` | B-01 through B-04 answer `403`, the frontend shows the note in `ParticipantScope` |
| no `realm_access.roles` | **all** admin routes `403`, the admin navigation is missing in the frontend |
| no `preferred_username` | `bookedBy` falls back to `'admin'`, the display to `'User'` |

**Rule:** hang your own mapper off the client, not off a client scope. Anyone who does need
a scope has to list the built-in ones in the export too — otherwise they take them away.

The actual state can only be inspected on the running instance, not on the export:

```bash
TOKEN=$(curl -s -X POST http://localhost:8090/realms/master/protocol/openid-connect/token \
  -d "grant_type=password&client_id=admin-cli&username=admin&password=admin" | jq -r .access_token)

# Has to contain profile, email, roles, web-origins and acr
curl -s -H "Authorization: Bearer $TOKEN" \
  http://localhost:8090/admin/realms/betting-game/client-scopes | jq -r '.[].name'
```

### Changes to the export only take effect after a re-import

`--import-realm` imports **only when the realm does not exist yet**. It lives in the volume
`betting-game_keycloak_db_data` and survives every `docker-compose restart`, every `up -d`
and every `down` without `-v`. Until then a change to `realm-export.json` has no effect:

```bash
docker-compose stop keycloak keycloak-db
docker-compose rm -f keycloak keycloak-db
docker volume rm betting-game_keycloak_db_data     # or: podman volume rm …
docker-compose up -d keycloak
docker-compose logs -f keycloak                    # wait for "Keycloak 26.7.x started"
```

This deletes **only** Keycloak, not the application database — `db_data` stays untouched.
If you would rather not rebuild the realm, apply the same change by hand in the admin
console: *Clients → betting-game-frontend → Client scopes* respectively *→ Dedicated scopes →
Add mapper*.

### Lifetimes

Values from `keycloak/realm-export.json`:

| Setting | Value | Meaning |
|---------|-------|---------|
| `accessTokenLifespan` | 3600 s (60 min) | validity of the access token |
| `ssoSessionIdleTimeout` | 1800 s (30 min) | the session expires after inactivity |
| `ssoSessionMaxLifespan` | 36000 s (10 h) | maximum session duration |

The refresh token is bound to the SSO session: 30 minutes idle, 10 hours at most.

## Security properties

**Backend** (active, `Kernel` → `AuthMiddleware` → `TokenVerifier`):

- Signature verification against the realm's public key from the JWKS endpoint
- An algorithm allowlist that *can* only contain asymmetric algorithms — so `alg: none` and
  HS256-with-the-public-key fail at the same place
- Expiry checks (`exp`, `nbf`, `iat`) with leeway against clock drift
- Realm validation: `iss` has to match verbatim
- `aud` optionally, where configured
- Key rotation: an unknown `kid` triggers exactly one throttled refetch
- Role-based access control for the admin endpoints

An unreachable Keycloak makes the API answer **503**, not 401 — a 401 would make every client
throw away its intact token and log in again at precisely the place we already know is not
working.

**Frontend** (active):

- PKCE against authorization code interception
- Token in memory only, not in localStorage (XSS protection)
- Automatic token refresh
- Silent SSO check for seamless sessions

## Testing

```bash
# Keycloak reachable? (the best check - /health is unavailable without KC_HEALTH_ENABLED)
curl http://localhost:8090/realms/betting-game

# Backend reachable?
curl http://localhost:8080/health          # {"status":"healthy","timestamp":"..."}

# Get a token
TOKEN=$(curl -s -X POST http://localhost:8090/realms/betting-game/protocol/openid-connect/token \
  -d "client_id=betting-game-frontend" \
  -d "username=testuser" -d "password=test123" \
  -d "grant_type=password" | jq -r .access_token)

# Call the API with the token - testuser has participant_id 2
curl http://localhost:8080/participants/2/bet-row -H "Authorization: Bearer $TOKEN"

# Someone else's data: 403, with an admin token too
curl -o /dev/null -w '%{http_code}\n' \
  http://localhost:8080/participants/1/fees -H "Authorization: Bearer $TOKEN"
```

## User management

### A new user through the admin console

1. Open <http://localhost:8090/admin>, log in `admin` / `admin`
2. Select the realm `betting-game`
3. Users → Add User → fill in the form
4. Credentials → Set Password
5. Attributes → create `participant_id` with the matching ID
6. Role Mappings → assign the role `user` (and `admin` where applicable)

### A new user through the realm export

```json
{
  "username": "newuser",
  "enabled": true,
  "emailVerified": true,
  "firstName": "New",
  "lastName": "User",
  "email": "new@bettinggame.local",
  "credentials": [{ "type": "password", "value": "password123", "temporary": false }],
  "realmRoles": ["user"],
  "attributes": { "participant_id": ["4"] }
}
```

## Troubleshooting

**Keycloak does not start**

```bash
docker-compose logs keycloak
lsof -i :8090                  # port taken?
docker-compose restart keycloak
```

Change the port in `docker-compose.yml`: `ports: ["8888:8080"]`.

**The frontend does not connect to Keycloak**

```bash
curl http://localhost:8090/realms/betting-game
cat frontend/.env              # VITE_KEYCLOAK_URL=http://localhost:8090
```

After changes to `.env`, restart the dev server. Check that the client's redirect URI
matches the URL being called.

**Endless loop between the frontend and the Keycloak login**

Symptom: after the login "Invalid or expired token" flashes up briefly, then it goes to the
Keycloak login and straight back again — endlessly.

Almost always this is **not** the token but the `iss` claim. Keycloak issues the token for a
browser and writes into it the URL the browser fetched it from
(`http://localhost:8090/realms/betting-game`). The API compares `iss` **verbatim**
(`hash_equals` in `TokenVerifier`) and, without `KEYCLOAK_ISSUER`, expects the value from
`KEYCLOAK_URL` — that is, the *internal* hostname
`http://keycloak:8080/realms/betting-game`. An intact token is rejected on that basis.

Two addresses for the same service, two different jobs — which is why the issuer has a
variable of its own, and why `docker-compose.yml` sets both:

| Variable | Value | What for |
|---|---|---|
| `KEYCLOAK_URL` | `http://keycloak:8080` | **reachability** — this is where the API fetches the JWKS |
| `KEYCLOAK_ISSUER` | `http://localhost:8090/realms/betting-game` | **identity** — what the token says |

Look up what is actually in there:

```bash
TOKEN=$(curl -s -X POST http://localhost:8090/realms/betting-game/protocol/openid-connect/token \
  -d "grant_type=password&client_id=betting-game-frontend&username=testuser&password=test123" \
  | jq -r .access_token)

echo "$TOKEN" | cut -d. -f2 | base64 -d 2>/dev/null | jq '.iss, .participant_id, .realm_access.roles'
docker-compose exec php printenv KEYCLOAK_ISSUER
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/participants/2/bet-row
```

Both `iss` values have to be character-for-character identical. After changing the
variables, `docker-compose up -d php` — a `restart` does not pick up changed `environment`
entries.

That the loop arises at all, rather than an error simply staying on screen, was additionally
a bug in the client: the response interceptor sent the user to the login on *every* `401`.
But Keycloak has a valid session, hands back the same token, and the game starts over. It
now only sends them to log in when there is no session at all — otherwise the message stays
put.

**The backend does not validate tokens**

```bash
docker-compose exec php printenv | grep KEYCLOAK
docker-compose exec php curl -s http://keycloak:8080/realms/betting-game | head -c 200
```

Note: the backend talks to Keycloak under the internal hostname `keycloak:8080`, the
frontend under `localhost:8090`. If Keycloak is unreachable altogether, the API answers
`503`, not `401` — a key problem is not an invalid token.

**Token expired** – the frontend refreshes automatically; manually:
`await keycloakService.updateToken(5)`.

## Production

1. Set strong passwords (`KEYCLOAK_ADMIN_PASSWORD`)
2. Enable HTTPS – your own certificate, or behind a reverse proxy (Caddy)
3. An external PostgreSQL instance instead of the container database
4. Back the realm up:
   `docker-compose exec keycloak /opt/keycloak/bin/kc.sh export --file /tmp/realm-backup.json`
5. Set `KEYCLOAK_ISSUER` to the public URL. This does not only apply behind a reverse proxy —
   it applies everywhere the browser and the API reach Keycloak under different addresses,
   so in the local Compose stack too
6. Set `KEYCLOAK_AUDIENCE` as soon as the client's mappers deliver a reliable `aud` — the
   check is deliberately off, because with the wrong value it locks everyone out

## Further reading

- [Keycloak Documentation](https://www.keycloak.org/documentation)
- [Keycloak JS Adapter](https://www.keycloak.org/docs/latest/securing_apps/#_javascript_adapter)
- [OAuth2 / OIDC Spec](https://openid.net/connect/)
