# Keycloak – Authentifizierung

Benutzerverwaltung und Authentifizierung über **Keycloak 26.7** mit OAuth2 / OpenID Connect.

> **Status:** vollständig verdrahtet. [`public/index.php`](public/index.php) reicht an
> [`Kernel`](src/Presentation/Http/Kernel.php) weiter, der `AuthMiddleware` vor jede Route
> hängt, die sich nicht ausdrücklich als öffentlich markiert. Die Signatur wird gegen den
> JWKS-Endpunkt des Realms geprüft — siehe
> [TokenVerifier](src/Infrastructure/Auth/TokenVerifier.php).

## Schnellstart

```bash
docker-compose up -d

# Keycloak braucht beim ersten Start 30-60 Sekunden für den Realm-Import
docker-compose logs -f keycloak    # warten auf "Keycloak 26.7.x started", dann Ctrl+C

make composer-install
```

> Wer nur die API testen will, holt sich das Token mit dem Snippet unter
> [Testen](#testen) und lässt den Frontend-Container aus.

Das Frontend läuft danach als Container auf Port 3000. Für Entwicklung mit Hot Reload
stattdessen den Vite-Dev-Server nutzen:

```bash
docker-compose stop frontend   # gibt Port 3000 frei, Vite nutzt denselben Port
cd frontend && npm install && npm run dev
```

### Login testen

1. <http://localhost:3000> öffnen
2. "Login with Keycloak" klicken
3. Demo-Credentials eingeben (siehe unten)

## Services

| Service | Port | URL | Credentials |
|---------|------|-----|-------------|
| Frontend | 3000 | <http://localhost:3000> | siehe Demo-Benutzer |
| Backend API | 8080 | <http://localhost:8080> | JWT Token |
| Keycloak | 8090 | <http://localhost:8090> | – |
| Admin Console | 8090 | <http://localhost:8090/admin> | admin / admin |

## Vorkonfigurierte Benutzer

Der Realm `betting-game` wird beim Start automatisch aus
[`keycloak/realm-export.json`](keycloak/realm-export.json) importiert.

| Username | Passwort | Rollen | `participant_id` |
|----------|----------|--------|------------------|
| `admin` | `admin123` | user, admin | 1 |
| `testuser` | `test123` | user | 2 |
| `john.doe` | `password` | user | 3 |

Der Claim `participant_id` entscheidet, wessen Daten ein Token lesen darf; die Rolle
`admin` gibt die Schreibendpunkte frei. Beides steht im Realm-Export, nicht in der
Anwendung.

## Architektur

```
┌─────────────┐         ┌──────────────┐         ┌──────────────┐
│   Frontend  │────────>│   Keycloak   │<────────│   Backend    │
│   (Vue.js)  │ Login   │   Server     │ Verify  │   (PHP API)  │
└─────────────┘         └──────────────┘         └──────────────┘
      │                        │                         │
      │ JWT Token              │ Public Key              │
      └───────────────────────────────────────────────────┘
```

Login-Ablauf:

1. User klickt "Login" → Redirect zur Keycloak Login-Seite
2. Keycloak validiert die Credentials und stellt einen Authorization Code aus
3. Keycloak-JS tauscht den Code gegen Access-/Refresh-/ID-Token (PKCE)
4. Axios-Interceptor hängt den Access Token an jeden API-Request
5. Backend validiert die Signatur gegen den Public Key des Realms

## Komponenten

### Backend

| Datei | Aufgabe |
|-------|---------|
| `src/Infrastructure/Auth/TokenVerifier.php` | Signatur- und Claim-Prüfung |
| `src/Infrastructure/Auth/JwkSet.php` | JWKS lesen, RSA-Schlüssel als PEM aufbauen |
| `src/Infrastructure/Auth/KeycloakKeys.php` | JWKS holen und cachen, Rotation |
| `src/Infrastructure/Auth/KeycloakService.php` | Token-Parsing, `participant_id`, Rollen |
| `src/Infrastructure/Auth/AuthMiddleware.php` | Request-Authentifizierung, User-Kontext |
| `src/Infrastructure/DI/Container.php` | Registrierung der Services |

```php
// Wirft InvalidTokenException, wenn das Token abgelehnt wird, und
// KeyUnavailableException, wenn sich das gerade nicht entscheiden lässt.
$tokenData     = $keycloakService->verifyToken($jwtToken);
$participantId = $keycloakService->getParticipantId($tokenData);
$roles         = $keycloakService->getRoles($tokenData);

if ($keycloakService->hasRole($tokenData, 'admin')) { /* ... */ }
```

```php
// In Router/Controller
$authResponse = $authMiddleware->handle($request);
if ($authResponse) {
    return $authResponse;             // 401 Unauthorized
}

$participantId = $request->attribute('participant_id');
$username      = $request->attribute('username');
$roles         = $request->attribute('roles');
```

### Frontend

| Datei | Aufgabe |
|-------|---------|
| `frontend/src/services/keycloak.js` | Keycloak-JS Wrapper (Init, Login, Logout, Token) |
| `frontend/src/stores/auth.js` | Pinia Store mit User-State und Rollen |
| `frontend/src/views/LoginView.vue` | Login-Seite |
| `frontend/public/silent-check-sso.html` | Silent SSO Check |
| `frontend/.env` | Keycloak- und API-URLs |

```javascript
const authStore = useAuthStore()

await authStore.initKeycloak()   // beim App-Start (main.js)
await authStore.login()
await authStore.logout()

authStore.isAuthenticated        // computed
authStore.username               // aus preferred_username
authStore.participantId          // aus Custom Claim
authStore.roles                  // aus realm_access.roles
authStore.isAdmin()              // hasRole('admin')
```

Der Axios-Interceptor holt den aktuellen Token, erneuert ihn bei Bedarf und setzt
`Authorization: Bearer <token>`. Bei `401` folgt ein Redirect zum Keycloak-Login.

## Konfiguration

**Backend** – [`config/config.php`](config/config.php), Werte aus Environment-Variablen:

```php
'keycloak' => [
    'url'                 => 'http://keycloak:8080',   // interner Docker-Hostname
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

| Client | Typ | Standard Flow | Direct Access Grants | Redirect URIs |
|--------|-----|---------------|----------------------|---------------|
| `betting-game-frontend` | Public (PKCE) | aktiv | aktiv | `http://localhost:3000/*` |
| `betting-game-api` | Bearer-Only | aus | aus | – |

## Token

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

`participant_id` wird aus den User-Attributen ins Token gemappt – kein separater Lookup nötig.
Der Mapper hängt **direkt am Client** `betting-game-frontend` (`protocolMappers`), nicht an
einem eigenen Client Scope. Warum, steht gleich darunter.

### Ein Client Scope im Realm-Export löscht die eingebauten

> Diese Falle hat den Realm zwischen dem Kurswechsel und dem 2026-07-29 unbrauchbar
> gemacht. Wer den Export anfasst, muss sie kennen.

Enthält ein Realm-Export einen **Top-Level-Block `clientScopes`**, versteht Keycloak ihn
als *die vollständige Liste* der Client Scopes des Realms — und legt die eingebauten
(`profile`, `email`, `roles`, `web-origins`, `acr`) dann **nicht** an.

Der Export dieses Projekts definierte genau einen Scope (`participant_id`). Die Folge:

- Der Realm hatte nur `participant_id` und `offline_access`.
- Die `defaultClientScopes` des Frontend-Clients verwiesen auf fünf Scopes, die es nicht
  gab. Keycloak verwirft solche Verweise **stillschweigend** — der Client stand am Ende
  mit *null* zugewiesenen Scopes da.
- Ausgestellte Token trugen daraufhin weder `participant_id` noch `preferred_username`
  noch `realm_access.roles`.

Und damit war die gesamte Autorisierung wirkungslos, ohne dass irgendwo ein Fehler stand:

| Wirkung | Folge |
|---|---|
| kein `participant_id` | B-01 bis B-04 antworten mit `403`, das Frontend zeigt den Hinweis in `ParticipantScope` |
| kein `realm_access.roles` | **alle** Adminrouten `403`, im Frontend fehlt die Admin-Navigation |
| kein `preferred_username` | `bookedBy` fällt auf `'admin'` zurück, die Anzeige auf `'User'` |

**Regel:** Einen eigenen Mapper an den Client hängen, nicht über einen Client Scope. Wer
doch einen Scope braucht, muss die eingebauten im Export mit auflisten — sonst nimmt er
sie weg.

Nachsehen lässt sich der Ist-Zustand nur an der laufenden Instanz, nicht am Export:

```bash
TOKEN=$(curl -s -X POST http://localhost:8090/realms/master/protocol/openid-connect/token \
  -d "grant_type=password&client_id=admin-cli&username=admin&password=admin" | jq -r .access_token)

# Muss profile, email, roles, web-origins und acr enthalten
curl -s -H "Authorization: Bearer $TOKEN" \
  http://localhost:8090/admin/realms/betting-game/client-scopes | jq -r '.[].name'
```

### Änderungen am Export wirken erst nach einem Neuimport

`--import-realm` importiert **nur, wenn der Realm noch nicht existiert**. Er liegt im
Volume `betting-game_keycloak_db_data` und überlebt jedes `docker-compose restart`, jedes
`up -d` und jedes `down` ohne `-v`. Eine Änderung an `realm-export.json` bleibt bis dahin
wirkungslos:

```bash
docker-compose stop keycloak keycloak-db
docker-compose rm -f keycloak keycloak-db
docker volume rm betting-game_keycloak_db_data     # oder: podman volume rm …
docker-compose up -d keycloak
docker-compose logs -f keycloak                    # warten auf "Keycloak 26.7.x started"
```

Das löscht **nur** Keycloak, nicht die Anwendungsdatenbank — `db_data` bleibt unberührt.
Wer den Realm nicht neu aufbauen will, setzt dieselbe Änderung von Hand in der Admin
Console: *Clients → betting-game-frontend → Client scopes* bzw. *→ Dedicated scopes →
Add mapper*.

### Lebensdauer

Werte aus `keycloak/realm-export.json`:

| Einstellung | Wert | Bedeutung |
|-------------|------|-----------|
| `accessTokenLifespan` | 3600 s (60 Min) | Gültigkeit des Access Tokens |
| `ssoSessionIdleTimeout` | 1800 s (30 Min) | Session verfällt nach Inaktivität |
| `ssoSessionMaxLifespan` | 36000 s (10 Std) | maximale Session-Dauer |

Der Refresh Token ist an die SSO-Session gebunden: 30 Minuten Idle, maximal 10 Stunden.

## Security-Eigenschaften

**Backend** (aktiv, `Kernel` → `AuthMiddleware` → `TokenVerifier`):

- Signaturprüfung gegen den Public Key des Realms aus dem JWKS-Endpunkt
- Algorithmus-Allowlist, die nur asymmetrische Verfahren enthalten *kann* — damit scheitern
  `alg: none` und HS256-mit-dem-öffentlichen-Schlüssel an derselben Stelle
- Ablaufprüfung (`exp`, `nbf`, `iat`) mit Leeway gegen Uhrendrift
- Realm-Validierung: `iss` muss exakt stimmen
- optional `aud`, wenn konfiguriert
- Schlüsselrotation: eine unbekannte `kid` löst genau einen gedrosselten Refetch aus
- rollenbasierte Zugriffskontrolle für Admin-Endpunkte

Nicht erreichbares Keycloak beantwortet die API mit **503**, nicht mit 401 — ein 401 würde
jeden Client dazu bringen, sein intaktes Token wegzuwerfen und sich ausgerechnet dort neu
anzumelden, wo wir schon wissen, dass es nicht geht.

**Frontend** (aktiv):

- PKCE gegen Authorization Code Interception
- Token nur im Speicher, nicht im localStorage (XSS-Schutz)
- automatischer Token-Refresh
- Silent SSO Check für nahtlose Sessions

## Testen

```bash
# Keycloak erreichbar? (bester Check - /health ist ohne KC_HEALTH_ENABLED nicht verfügbar)
curl http://localhost:8090/realms/betting-game

# Backend erreichbar?
curl http://localhost:8080/health          # {"status":"healthy","timestamp":"..."}

# Token holen
TOKEN=$(curl -s -X POST http://localhost:8090/realms/betting-game/protocol/openid-connect/token \
  -d "client_id=betting-game-frontend" \
  -d "username=testuser" -d "password=test123" \
  -d "grant_type=password" | jq -r .access_token)

# API mit Token aufrufen - testuser hat participant_id 2
curl http://localhost:8080/participants/2/bet-row -H "Authorization: Bearer $TOKEN"

# Fremde Daten: 403, auch mit einem Admin-Token
curl -o /dev/null -w '%{http_code}\n' \
  http://localhost:8080/participants/1/fees -H "Authorization: Bearer $TOKEN"
```

## Benutzerverwaltung

### Neuen Benutzer über die Admin Console

1. <http://localhost:8090/admin> öffnen, Login `admin` / `admin`
2. Realm `betting-game` wählen
3. Users → Add User → Formular ausfüllen
4. Credentials → Set Password
5. Attributes → `participant_id` mit der passenden ID anlegen
6. Role Mappings → Rolle `user` (und ggf. `admin`) zuweisen

### Neuen Benutzer über den Realm-Export

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

**Keycloak startet nicht**

```bash
docker-compose logs keycloak
lsof -i :8090                  # Port belegt?
docker-compose restart keycloak
```

Port ändern in `docker-compose.yml`: `ports: ["8888:8080"]`.

**Frontend verbindet nicht zu Keycloak**

```bash
curl http://localhost:8090/realms/betting-game
cat frontend/.env              # VITE_KEYCLOAK_URL=http://localhost:8090
```

Nach Änderungen an `.env` den Dev-Server neu starten. Prüfen, ob die Redirect-URI des
Clients zur aufgerufenen URL passt.

**Endlosschleife zwischen Frontend und Keycloak-Login**

Symptom: Nach dem Login blitzt kurz „Invalid or expired token" auf, dann geht es zur
Keycloak-Anmeldung und sofort wieder zurück — endlos.

Fast immer ist es **nicht** das Token, sondern der `iss`-Claim. Keycloak stellt das Token
für einen Browser aus und schreibt die URL hinein, unter der der Browser es geholt hat
(`http://localhost:8090/realms/betting-game`). Die API vergleicht `iss` **exakt**
(`hash_equals` in `TokenVerifier`) und erwartet ohne `KEYCLOAK_ISSUER` den Wert aus
`KEYCLOAK_URL` — also den *internen* Hostnamen `http://keycloak:8080/realms/betting-game`.
Ein intaktes Token wird damit abgelehnt.

Zwei Adressen für denselben Dienst, zwei verschiedene Aufgaben — deshalb hat der Issuer
eine eigene Variable, und deshalb setzt `docker-compose.yml` beide:

| Variable | Wert | Wofür |
|---|---|---|
| `KEYCLOAK_URL` | `http://keycloak:8080` | **Erreichbarkeit** — von hier holt die API das JWKS |
| `KEYCLOAK_ISSUER` | `http://localhost:8090/realms/betting-game` | **Identität** — was im Token steht |

Nachsehen, was tatsächlich drinsteht:

```bash
TOKEN=$(curl -s -X POST http://localhost:8090/realms/betting-game/protocol/openid-connect/token \
  -d "grant_type=password&client_id=betting-game-frontend&username=testuser&password=test123" \
  | jq -r .access_token)

echo "$TOKEN" | cut -d. -f2 | base64 -d 2>/dev/null | jq '.iss, .participant_id, .realm_access.roles'
docker-compose exec php printenv KEYCLOAK_ISSUER
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/participants/2/bet-row
```

Beide `iss`-Werte müssen zeichengleich sein. Nach einer Änderung an den Variablen
`docker-compose up -d php` — ein `restart` übernimmt geänderte `environment`-Einträge nicht.

Dass die Schleife entsteht und nicht einfach ein Fehler stehenbleibt, war zusätzlich ein
Fehler im Client: Der Response-Interceptor schickte bei *jedem* `401` zur Anmeldung.
Keycloak hat aber eine gültige Sitzung, gibt dasselbe Token zurück, und das Spiel beginnt
von vorn. Er meldet jetzt nur noch an, wenn gar keine Sitzung besteht — andernfalls bleibt
die Meldung stehen.

**Backend validiert Token nicht**

```bash
docker-compose exec php printenv | grep KEYCLOAK
docker-compose exec php curl -s http://keycloak:8080/realms/betting-game | head -c 200
```

Beachte: Das Backend spricht Keycloak unter dem internen Hostnamen `keycloak:8080` an,
das Frontend unter `localhost:8090`. Ist Keycloak gar nicht erreichbar, antwortet die API
mit `503`, nicht mit `401` — ein Schlüsselproblem ist kein ungültiges Token.

**Token abgelaufen** – das Frontend erneuert automatisch; manuell:
`await keycloakService.updateToken(5)`.

## Production

1. Starke Passwörter setzen (`KEYCLOAK_ADMIN_PASSWORD`)
2. HTTPS aktivieren – eigenes Zertifikat oder hinter Reverse Proxy (Caddy)
3. Externe PostgreSQL-Instanz statt Container-Datenbank
4. Realm sichern:
   `docker-compose exec keycloak /opt/keycloak/bin/kc.sh export --file /tmp/realm-backup.json`
5. `KEYCLOAK_ISSUER` auf die öffentliche URL setzen. Das gilt nicht erst hinter einem
   Reverse Proxy — es gilt überall dort, wo Browser und API Keycloak unter verschiedenen
   Adressen erreichen, also auch im lokalen Compose-Stack
6. `KEYCLOAK_AUDIENCE` setzen, sobald die Mapper des Clients eine verlässliche `aud`
   liefern — die Prüfung ist bewusst aus, weil sie mit dem falschen Wert alle aussperrt

## Weiterführend

- [Keycloak Documentation](https://www.keycloak.org/documentation)
- [Keycloak JS Adapter](https://www.keycloak.org/docs/latest/securing_apps/#_javascript_adapter)
- [OAuth2 / OIDC Spec](https://openid.net/connect/)
