# Frontend – Vue.js 3 SPA

Oberfläche für die **Lotto-6-aus-49-Tippgemeinschaft**. Sie bedient die Endpunkte aus
[betting_game_api.yaml](betting_game_api.yaml); maßgeblich für die Fachlichkeit ist
[USER_STORIES.md](USER_STORIES.md), für die Routen
[Router.php](src/Presentation/Router/Router.php).

Setup-Hinweise stehen in [`frontend/README.md`](frontend/README.md), die Auth-Details in
[KEYCLOAK.md](KEYCLOAK.md).

> **Vorgeschichte.** Bis zum 2026-07-29 war hier eine SPA des alten Sportwetten-Tippspiels
> dokumentiert (Predictions, Scores, Games). Diese Endpunkte gibt es seit dem Kurswechsel
> auf die Lotterie (`f1d0771`) nicht mehr; jeder fachliche Request lief in einen `404`. Die
> SPA ist auf die aktuelle API umgestellt worden — Views, Router und API-Client sind
> ersetzt, Auth-Store und Keycloak-Wrapper blieben.

## Überblick

| Metrik | Wert |
|--------|------|
| Views | 12 (1 Login, 5 Teilnehmer, 6 Admin) |
| Komponenten | 2 gemeinsame + `App.vue` |
| Routen | 14 (inkl. Redirect `/` → `/bet-row` und Catch-all) |
| Services | 3 (API-Client, Fehlermeldungen, Keycloak-Wrapper) |
| Sonstiges | 1 Composable, 1 Formatierungsmodul, 1 Auth-Store, 1 Stylesheet |

**Stack:** Vue 3.4 (Composition API, `<script setup>`), Vue Router 4.2, Pinia 2.1,
Axios 1.6, keycloak-js 23, Vite 5.

## Ausbaustufe Basis: was die Oberfläche zeigen darf

Teilnehmer lesen ausschließlich, der Administrator schreibt alles. Die SPA bildet das ab —
in den fünf Teilnehmeransichten gibt es keinen einzigen Absende-Button, weil es dafür
keinen Endpunkt gibt. Selbstverwaltung ist E1 und nicht implementiert.

## Views und Routen

| View | Route | Endpunkt | Story |
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
| AdminTippYearsView | `/admin/tipp-years` | Tippjahre, Status, Perioden, Mitglieder, Scheine, Ausschüttung | B-10 – B-14, B-18 |
| AdminOperationsView | `/admin/operations` | `GET /commands/{id}`, `GET /admin/audit/…`, `GET/POST /admin/projections…` | OPS-01, OPS-03, OPS-04 |

`/` leitet auf `/bet-row` um. Eine Catch-all-Route fängt unbekannte Pfade ab — darunter
alle URLs der alten SPA, die sonst als weiße Seite endeten.

Routen mit `requiresAuth` verlangen einen Login, `/admin/*` zusätzlich `requiresAdmin`.
Der Guard verbirgt nur den Eingang; die Rolle prüft die API auf jeder Adminroute selbst,
und dort fällt die Entscheidung.

**Der Guard ist `async` und wartet auf `authStore.ready()`.** Vue Router startet seine
erste Navigation bereits in `app.use(router)` — also bevor `main.js` den Keycloak-Start
abgewartet hat. Ohne das Warten beurteilte der Guard einen angemeldeten Benutzer als
anonym, schickte die angefragte Route auf `/login`, und bis die Session zurück war, war
das Ziel verloren: Jeder Deep-Link und jeder Reload einer geschützten Seite landete auf
`/bet-row`. `ready()` liefert dieselbe memoisierte Zusage, die auch der App-Start abwartet.

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

### Der `participant_id`-Claim

Die vier Teilnehmeransichten und `DrawsView` brauchen den `participant_id`-Claim aus dem
Token. Fehlt er, zeigt `ParticipantScope.vue` einen Hinweis statt Daten.

Das ist Absicht und keine Lücke: Die API leitet die Identität aus dem Token ab, nie aus
dem Pfad, und `Authorization::requireSelf()` lässt dort auch einen Administrator nicht
durch. Ein Admin ohne eigene `participant_id` sieht diese Ansichten leer — seine Sicht auf
fremde Daten sind die Admin-Endpunkte.

## API-Integration

```
Vue-Komponente → api.js (Axios) → Request-Interceptor (Token) →
Proxy /api → Backend → Response-Interceptor (401) → Komponente
```

Proxy in `vite.config.js`: `/api` → `http://localhost:8080`, Präfix wird entfernt. Im
Container übernimmt das `nginx.conf`. Dev-Server auf Port 3000.

`services/api.js` hat für jede Route genau eine Methode:

```javascript
// Teilnehmer, nur lesend
api.getBetRow(participantId, betPeriodId)
api.getMemberships(participantId, tippYearId)
api.getFees(participantId, { tippYearId, paymentStatus })
api.getPayoutShare(participantId, tippYearId)
api.getDraws(tippYearId, { status, withWinningsOnly })
api.getCommandStatus(commandId)

// Administrator – unter api.admin.*, Commands mit Idempotency-Key
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

### Commands und der Idempotency-Key

Der Schlüssel wird nicht im API-Client vergeben, sondern in
`composables/useCommand.js` — nur der Aufrufer weiß, ob ein zweiter Klick eine
Wiederholung desselben Vorhabens ist oder ein neuer Command:

- **Keine Antwort** (Timeout, Netzwerk): Der Schlüssel bleibt. Ein erneuter Klick
  wiederholt denselben Command, und die API liefert das gespeicherte Ergebnis mit
  `Idempotent-Replay: true` zurück, statt ein zweites Mal zu buchen. Genau dafür
  existiert der Header.
- **Irgendein Status**: Der Schlüssel ist verbraucht. Ein Schlüssel, dessen erster Versuch
  fehlschlug, bleibt serverseitig vergeben; ihn nach einem `400` weiterzuverwenden, würde
  einen behebbaren Eingabefehler dauerhaft in ein `409` verwandeln.

`AdminDrawsView` hält deshalb **einen** Command-Zustand **je Ziehung**: Ein aus einer
Zeile übriggebliebener Schlüssel dürfte nicht die Buchung der nächsten Zeile beantworten.

Die `commandId` aus der `202` wird angezeigt und verlinkt auf **Betrieb →
Verarbeitungsstand** (`GET /commands/{id}`). Der Endpunkt ist bewusst nicht
admin-geschützt.

### Ehrlich zur Asynchronität

Die API beschreibt Commands als asynchron, die Implementierung schreibt synchron: Bei
Ankunft der `202` sind Event Store und Lesemodelle bereits aktuell. Die Admin-Ansichten
laden unmittelbar danach neu — kein Rennen, sondern die Konsequenz daraus.

## Fehlerbehandlung

`services/errors.js` zeigt die `message` aus der API-Antwort statt der Axios-Meldung:
„Request failed with status code 409“ sagt nicht, welche Geschäftsregel Nein gesagt hat.

| Status | Verhalten |
|---|---|
| `401` | Interceptor leitet zur Keycloak-Anmeldung |
| `403` | Meldung der API (z. B. „You may only access your own data“) |
| `404` | in Leseansichten ein **Leerzustand**, kein Fehler — „für diese Periode ist keine Reihe hinterlegt“ ist eine Aussage |
| `409` | Meldung der abgelehnten Geschäftsregel |
| `503` | Hinweis, dass der Aufruf wiederholbar ist — **keine** Weiterleitung zur Anmeldung |

Die Unterscheidung `401` / `503` ist der Grund, warum der Interceptor nur auf `401`
reagiert: Ein `503` heißt, dass Keycloak gerade nicht antwortet. Den Benutzer dorthin zu
schicken, hieße ihn ausgerechnet zu dem Dienst zu schicken, von dem wir wissen, dass er
nicht erreichbar ist.

## Aufbau der Quellen

```
frontend/src/
├── views/                 11 Seiten, eine je Ansicht der Tabelle oben
├── components/
│   ├── CommandFeedback.vue    Antwort eines Commands inkl. commandId
│   └── ParticipantScope.vue   Hinweis, wenn dem Token participant_id fehlt
├── composables/useCommand.js  useCommand (Idempotency-Key) und useQuery
├── services/
│   ├── api.js                 eine Methode je Route
│   ├── errors.js              Fehlermeldung aus der API-Antwort
│   └── keycloak.js            keycloak-js-Wrapper
├── stores/auth.js             Pinia-Auth-Store
├── support/format.js          Geld, Datum, Lottozahlen, Statuslabels
├── assets/app.css             gemeinsames Design System
├── router/index.js
├── App.vue
└── main.js
```

## Authentifizierung

Login vollständig über Keycloak (OAuth2/OIDC mit PKCE). Tokens liegen ausschließlich im
Speicher des Keycloak-JS-Adapters, **nicht** im localStorage.

```javascript
await authStore.initKeycloak()   // beim App-Start (main.js)
await authStore.login()          // Redirect zur Keycloak-Login-Seite
await authStore.logout()         // Keycloak-Logout + lokalen State leeren

keycloakService.onTokenExpired(() => keycloakService.updateToken(30))
```

Demo-Benutzer und Realm-Details: [KEYCLOAK.md](KEYCLOAK.md).

## Design System

Gemeinsam in `src/assets/app.css`, nicht als Scoped Styles je Komponente — die alte SPA
trug dieselben Card-, Button- und Badge-Regeln neunmal, und jede Farbänderung war neunmal
zu machen.

```css
--blue:     #2563eb;   /* primäre Aktionen */
--green:    #10b981;   /* Gewinne, Erfolg */
--yellow:   #f59e0b;   /* offene Posten, Superzahl */
--red:      #ef4444;   /* Fehler, unumkehrbare Aktionen */
--gray-900: #1f2937;   /* Überschriften */
--gray-600: #6b7280;   /* Fließtext */
--gray-300: #d1d5db;   /* Rahmen */
--gray-100: #f3f4f6;   /* Flächen */
```

**Bausteine:** `.card` / `.card-grid`, `.facts` (Definitionsliste), `table.data`,
`.numbers .ball` (Lottokugeln, Superzahl gelb), `.badge` mit Statusklassen, `.field` /
`.field-row` / `.field-inline`, `.btn-primary|secondary|danger|link`, `.state`
(`loading`, `empty`, `error`, `success`, `note`).

**Responsive:** Mobile First, Grid mit `repeat(auto-fill, minmax(320px, 1fr))`, Tabellen
in `.table-wrap` mit horizontalem Scroll.

## Entwicklung

```bash
cd frontend
npm install
npm run dev        # http://localhost:3000 (Backend muss auf :8080 laufen)
npm run build      # Ausgabe nach dist/
npm run lint       # prüft, ändert nichts
npm run lint:fix   # korrigiert das automatisch Korrigierbare
```

### Regelsatz

`eslint:recommended` + `plugin:vue/vue3-recommended`, konfiguriert in
[`frontend/.eslintrc.cjs`](frontend/.eslintrc.cjs). `vue3-recommended` ist die strengste
der drei Vue-Voreinstellungen — sie stapelt *essential* (echte Fehler),
*strongly-recommended* (Lesbarkeit) und *recommended* (Reihenfolge und Benennung).
Genau diese Kombination empfiehlt die Vue-Dokumentation selbst, wer sie kennt, muss hier
keine Hausregeln lernen.

Eine Ausnahme ist konfiguriert: `vue/multi-word-component-names` erlaubt `App` — die eine
Komponente, die sinnvoll kein zweites Wort trägt.

Der Bestand ist **fehlerfrei** — halte ihn so. Ohne lokales Node läuft die Prüfung im
Container:

```bash
docker run --rm -v "$PWD/frontend:/app" -w /app node:24-alpine \
  sh -c "npm install && npm run lint"
```

Die Formatierungsregeln (`max-attributes-per-line`,
`singleline-html-element-content-newline`) sind bewusst **nicht** abgeschaltet, obwohl sie
die Templates länger machen: Sie ersetzen den Formatter, den dieses Projekt nicht hat.
Wer sie doch abschaltet, braucht dafür einen — sonst driftet die Formatierung wieder
auseinander.

Läuft parallel der Frontend-Container, belegt dieser Port 3000 — vorher
`docker-compose stop frontend`.

### Deployment

```bash
docker-compose build frontend && docker-compose up -d
# Frontend :3000 | API :8080 | PHPMyAdmin :8081 | Keycloak :8090
```

Statisches Hosting (Netlify, Vercel): `npm run build`, dann `dist/` deployen. Bei
manuellem Nginx zusätzlich `try_files $uri $uri/ /index.html;` und einen `/api/`-Proxy.

## Testing

**Vitest** deckt Composables, Stores, Services und den Router-Guard ab —
[`tests/unit/`](frontend/tests/unit/), gegliedert wie die Backend-Tests in `tests/Unit`.
Jede Datei ist an einer konkreten User Story oder Akzeptanzkriterium verankert, nicht an der
Implementierung:

| Datei | Prüft |
|---|---|
| `composables/useCommand.spec.js` | OPS-02: der Idempotency-Key bleibt bei einer antwortlosen Anfrage erhalten (Retry wiederholt denselben Command) und wird bei jeder Antwort — Erfolg wie Fehler — verworfen; `useQuery` fällt bei Fehlern auf den Ausgangswert zurück (B-01: 404 ist ein Leerzustand) |
| `services/errors.spec.js` | `apiMessage` für 401 (iss-Claim-Hinweis), 503 (wiederholbar), durchgereichte API-Nachricht, nicht erreichbare API |
| `support/format.spec.js` | `formatAmount(null)` ≠ `formatAmount(0)` (B-04: Anteil ist `null`, bis die Ausschüttung gebucht ist); `parseNumbers` gegen B-06 (genau sechs verschiedene Zahlen 1–49, aufsteigend) |
| `stores/auth.spec.js` | `isAdmin()`/`hasRole()` (B-17), `displayName`-Fallback, `logout()` räumt lokalen State auch dann auf, wenn der Keycloak-Logout selbst fehlschlägt |
| `router/guard.spec.js` | `requiresAuth`/`requiresAdmin` (B-15 bis B-17): anonym → `/login`, Teilnehmer → kein Zutritt zu `/admin/*` |
| `components/ParticipantScope.spec.js` | Fehlender `participant_id`-Claim zeigt den Hinweis statt der Teilnehmeransichten |

```bash
npm test          # einmaliger Lauf
npm run test:watch
```

Ohne lokales Node läuft das im selben Container wie Lint:

```bash
docker run --rm -v "$PWD/frontend:/app" -w /app node:24-alpine sh -c "npm install && npm test"
```

**Playwright** deckt den Durchstich gegen den echten, laufenden Stack ab —
[`tests/e2e/`](frontend/tests/e2e/): echter Keycloak-Login, echte API, echte Lesemodelle.

| Datei | Prüft |
|---|---|
| `auth.spec.js` | Echter Keycloak-Login (B-15), Admin-Bereich für Teilnehmer unerreichbar (B-17), Logout |
| `participant-views.spec.js` | B-01, B-03, B-05 mit echten, gesäten Daten für `testuser` |
| `admin-fee-payment.spec.js` | B-07 als echter Schreibvorgang durch die Oberfläche, nicht nur ein Read |
| `admin-participants.spec.js` | B-21: Teilnehmer anlegen, und dass er danach in den Auswahlfeldern auftaucht |

```bash
docker-compose up -d          # der volle Stack muss laufen, .env baut localhost:* fest ein
npm run test:e2e
```

Ohne lokales Node im offiziellen Playwright-Image. `--network host`, damit `localhost` im
Browser dieselben Ports trifft wie auf dem Entwicklungsrechner; die Artefakte landen
absichtlich **im Container**, sonst gehören sie hinterher einer UID, die der Host nicht mehr
aufräumen kann:

```bash
docker run --rm --network host -e PLAYWRIGHT_OUTPUT_DIR=/tmp/pw-results \
  -v "$PWD:/repo" -w /repo/frontend \
  mcr.microsoft.com/playwright:v1.62.1-noble sh -c "npm run test:e2e"
```

### Was `global-setup.js` vorbereitet

Es sät ein komplettes Tippjahr durch die echten Command-Handler — derselbe Ablauf wie in
[QUICKSTART.md](QUICKSTART.md), nur automatisiert statt mit `curl`. Drei Eigenheiten, die
nicht offensichtlich sind und ohne die die Suite beim **zweiten** Lauf bricht:

- **Jeder Lauf bekommt ein eigenes Kalenderjahr.** Tippjahre dürfen sich nicht überlappen
  (`TippYear::assertNoOverlap`), also wählt das Setup das erste Jahr nach dem spätesten
  vorhandenen `endDate`. Ein fester Zeitraum ließe genau einen Lauf zu und danach nur noch `409`.
- **Das Jahr bleibt am Ende `closed`.** B-18 erlaubt höchstens ein laufendes Tippjahr; ein
  laufend zurückgelassenes Jahr blockiert den nächsten Lauf.
- **Jeder Test bekommt seine eigene Gebühr.** Der Admin-Test bucht die des Administrators,
  der Teilnehmer-Test liest die von `testuser` — sonst hinge das Ergebnis daran, in welcher
  Reihenfolge die Dateien zufällig laufen.

Teilnehmer anzulegen hat keinen Endpunkt (Selbstregistrierung ist E1-01), deshalb liegt das
als [`database/seed-demo-participants.sql`](database/seed-demo-participants.sql) daneben. Das
Setup spielt es über `docker-compose` ein; ist die Compose-CLI nicht erreichbar (etwa im
Container oben), warnt es und setzt voraus, dass die Zeilen schon da sind:

```bash
docker-compose exec -T db mariadb -uroot -psecret betting_game < database/seed-demo-participants.sql
```

### Warum die Ansichtstests über die Navigation klicken

`page.goto()` funktioniert, seit der Guard auf den Keycloak-Start wartet (siehe
„Navigations-Guard" oben) — `auth.spec.js` prüft genau das. Die Ansichtstests klicken
trotzdem über `navigateTo()` aus `fixtures.js`: derselbe Weg, den ein Benutzer nimmt, und
ohne vollständigen Reload je Test.

Manuelle Checkliste für das, was auch Playwright nicht abdeckt:

- [ ] Login über Keycloak, Logout, Redirect auf `/login` ohne Session
- [ ] Session bleibt nach Reload erhalten (Silent SSO)
- [ ] `/admin/*` nur mit Admin-Rolle erreichbar
- [ ] Token ohne `participant_id`: Teilnehmeransichten zeigen den Hinweis, keinen Fehler
- [ ] Tippjahr anlegen → auf `running` setzen → Periode anlegen → Mitglied aufnehmen →
      Reihe zuordnen → Tippschein einreichen → Ziehung eintragen → Gewinn nachtragen →
      Gebühr buchen (der Durchstich aus [QUICKSTART.md](QUICKSTART.md))
- [ ] Status-Dropdown: zweites Jahr auf `running` → `409`, und das Dropdown springt auf
      den alten Wert zurück statt eine Lüge stehenzulassen
- [ ] Tippschein auf ein Tippjahr im Status `planned`: `409` mit lesbarer Meldung
- [ ] Ausschüttung ohne Häkchen: Button bleibt gesperrt
- [ ] Gebühr ohne Zahlen im System: Leerzustand statt Fehler
- [ ] Betrieb: Projektionen anzeigen, eine neu aufbauen, Event-Historie einer Tippreihe
- [ ] Responsive Layout, Loading-, Leer- und Fehlerzustände

## Troubleshooting

**API-Calls schlagen fehl** – läuft das Backend auf 8080 (`curl http://localhost:8080/health`)?
Proxy in `vite.config.js` prüfen; CORS-Header setzt Caddy.

**Login funktioniert nicht** – siehe [KEYCLOAK.md](KEYCLOAK.md), Abschnitt „Troubleshooting“.

**Teilnehmeransichten zeigen den `participant_id`-Hinweis** – das Token trägt den Claim
nicht. Er kommt aus dem Benutzerattribut im Realm, siehe `keycloak/realm-export.json`.

**Alles leer, aber ohne Fehler** – vermutlich sind schlicht keine Daten angelegt.
[QUICKSTART.md](QUICKSTART.md) spielt ein Tippjahr von Hand durch.

**Frontend-Container startet nicht**

```bash
docker-compose build frontend --no-cache
docker-compose logs frontend
```

**Build-Fehler** – `rm -rf node_modules dist && npm install && npm run build`

## Offene Punkte

- **Teilnehmer sehen ihre `participant_id` nirgends verknüpft.** B-21 legt Teilnehmer an,
  aber die Zuordnung zum Keycloak-Benutzer geschieht weiterhin von Hand über das
  Realm-Attribut `participant_id`. Solange die beiden auseinanderlaufen, zeigen die
  Teilnehmeransichten fremde oder gar keine Daten. Das sauber zu schließen heißt, Konten
  zu verwalten — E1.
- **Keine Selbstverwaltung.** Teilnehmer lesen ausschließlich; Registrierung, Profil und
  eigene Reihenwahl sind E1-01 bis E1-03.
- Danach erst: TypeScript, Dark Mode, Mehrsprachigkeit.
