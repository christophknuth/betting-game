# Frontend – Lotterie-Tippgemeinschaft

Vue-3-SPA für die **Lotto-6-aus-49-Tippgemeinschaft**. Sie bedient die Endpunkte aus
[`../betting_game_api.yaml`](../betting_game_api.yaml); die Zuordnung Ansicht → Endpunkt
steht in [`../FRONTEND.md`](../FRONTEND.md), die Fachlichkeit in
[`../USER_STORIES.md`](../USER_STORIES.md), die Anmeldung in
[`../KEYCLOAK.md`](../KEYCLOAK.md).

**Ausbaustufe Basis:** Teilnehmer lesen ausschließlich, der Administrator schreibt alles.
Die SPA bildet genau das ab — die Teilnehmeransichten haben keinen einzigen Absende-Button.

## Voraussetzungen

- Node.js 18+
- API auf `http://localhost:8080` (`curl http://localhost:8080/health`)
- Keycloak auf `http://localhost:8090`, Realm `betting-game`

## Entwicklung

```bash
cd frontend
npm install
npm run dev        # http://localhost:3000
npm run build      # Ausgabe nach dist/
npm run lint       # prüft, ändert nichts
npm run lint:fix   # korrigiert, was automatisch korrigierbar ist
```

Ohne lokales Node läuft beides im Container:

```bash
docker run --rm -v "$PWD:/app" -w /app node:18-alpine sh -c "npm install && npm run lint"
```

Regelsatz: `eslint:recommended` + `plugin:vue/vue3-recommended` (siehe
[`.eslintrc.cjs`](.eslintrc.cjs)). `vue3-recommended` ist die strengste der drei
Vue-Voreinstellungen; sie enthält neben den Fehlerregeln auch die Formatierungs- und
Reihenfolgeregeln. Der Bestand ist fehlerfrei — **halte ihn so**.

Läuft parallel der Frontend-Container aus `docker-compose.yml`, belegt der Port 3000 —
vorher `docker-compose stop frontend`.

## Projektstruktur

```
frontend/
├── src/
│   ├── views/
│   │   ├── LoginView.vue              # Keycloak-Anmeldung
│   │   ├── BetRowView.vue             # B-01 eigene Tippreihe
│   │   ├── MembershipsView.vue        # B-02 eigene Teilnahmen
│   │   ├── FeesView.vue               # B-03 eigene Gebühren
│   │   ├── PayoutShareView.vue        # B-04 eigener Gewinnanteil
│   │   ├── DrawsView.vue              # B-05 Ziehungen des Tippjahres
│   │   ├── AdminTippYearsView.vue     # B-10 bis B-14
│   │   ├── AdminBetRowsView.vue       # B-06 Reihe zuordnen
│   │   ├── AdminDrawsView.vue         # B-08, B-09
│   │   ├── AdminFeesView.vue          # B-07 Gebühren buchen
│   │   └── AdminOperationsView.vue    # OPS-01, OPS-03, OPS-04
│   ├── components/
│   │   ├── CommandFeedback.vue        # Antwort eines Commands inkl. commandId
│   │   └── ParticipantScope.vue       # Hinweis, wenn dem Token participant_id fehlt
│   ├── composables/useCommand.js      # Command-/Query-Zustand, Idempotency-Key
│   ├── services/
│   │   ├── api.js                     # Axios-Client, eine Methode je Route
│   │   ├── errors.js                  # Fehlermeldung aus der API-Antwort
│   │   └── keycloak.js                # keycloak-js-Wrapper
│   ├── stores/auth.js                 # Pinia-Auth-Store
│   ├── support/format.js              # Geld, Datum, Lottozahlen, Statuslabels
│   ├── assets/app.css                 # gemeinsames Design System
│   ├── router/index.js
│   ├── App.vue
│   └── main.js
├── public/silent-check-sso.html
├── .env                               # Keycloak- und API-URLs
├── vite.config.js
├── Dockerfile                         # Build + Nginx
└── nginx.conf
```

## Anmeldung

Login über Keycloak (OIDC mit PKCE). Das Token liegt im Speicher des Adapters, **nicht**
im localStorage. `participant_id`, Username und Rollen kommen als Claims aus dem JWT.

Demo-Benutzer aus `keycloak/realm-export.json`:

```
admin    / admin123   (Rollen user + admin, participant_id 1)
testuser / test123    (Rolle  user,         participant_id 2)
john.doe / password   (Rolle  user,         participant_id 3)
```

Ohne `participant_id` im Token zeigen die Teilnehmeransichten einen Hinweis statt Daten.
Das ist keine Lücke: Die API leitet die Identität aus dem Token ab und lässt dort auch
einen Administrator nicht durch — der hat eigene Endpunkte.

## API-Anbindung

```
Vue-Komponente → api.js (Axios) → Request-Interceptor (Token) →
Proxy /api → Backend → Response-Interceptor (401) → Komponente
```

Der Proxy steht in `vite.config.js` (`/api` → `http://localhost:8080`, Präfix wird
entfernt); im Container übernimmt das `nginx.conf`.

`api.js` hat für jede Route in
[`../src/Presentation/Router/Router.php`](../src/Presentation/Router/Router.php) genau eine
Methode — Teilnehmerrouten direkt, Adminrouten unter `api.admin.*`.

### Commands und der Idempotency-Key

Schreibende Aufrufe nehmen einen `Idempotency-Key` entgegen. Vergeben wird er in
`composables/useCommand.js`, und zwar bewusst nicht bei jedem Klick neu:

- Kommt **keine Antwort** zurück (Timeout, Netzwerk), bleibt der Schlüssel bestehen. Ein
  erneuter Klick wiederholt denselben Command; die API antwortet mit dem gespeicherten
  Ergebnis und `Idempotent-Replay: true`, statt ein zweites Mal zu buchen.
- Kommt **irgendein Status** zurück, ist der Schlüssel verbraucht. Ein Schlüssel, dessen
  erster Versuch fehlschlug, bleibt serverseitig vergeben — ihn nach einem `400`
  weiterzuverwenden, würde einen behebbaren Eingabefehler dauerhaft in ein `409` verwandeln.

Antworten auf Commands sind `202` mit einer `commandId`. Sie wird angezeigt und verlinkt
auf **Betrieb → Verarbeitungsstand**; das ist der einzige Weg, später nachzusehen, was ein
Versuch erzeugt hat.

### Ehrlich zur Asynchronität

Die API beschreibt Commands als asynchron, die Implementierung schreibt synchron: Wer die
`202` hat, sieht in den Lesemodellen bereits das Ergebnis. Deshalb laden die Admin-Ansichten
unmittelbar nach einem Command neu — das ist hier kein Rennen.

## Fehlerbehandlung

`services/errors.js` zeigt die `message` aus der API-Antwort, nicht die von Axios: „Request
failed with status code 409“ sagt nicht, welche Regel Nein gesagt hat.

Zwei Statuscodes werden unterschiedlich behandelt:

- `401` — das Token wurde abgelehnt, der Interceptor schickt zur Anmeldung.
- `503` — Keycloak ist nicht erreichbar. Der Aufruf ist wiederholbar, das Token bleibt
  gültig; hier zur Anmeldung zu schicken hieße, den Benutzer ausgerechnet zu dem Dienst zu
  schicken, von dem wir wissen, dass er gerade nicht antwortet.

Ein `404` ist in den Leseansichten eine Aussage („für diese Periode ist keine Reihe
hinterlegt“) und wird als Leerzustand gezeigt, nicht als Fehler.

## Deployment

```bash
docker-compose build frontend && docker-compose up -d
# Frontend :3000 | API :8080 | PHPMyAdmin :8081 | Keycloak :8090
```

Statisches Hosting: `npm run build`, dann `dist/` ausliefern. Bei eigenem Nginx zusätzlich
`try_files $uri $uri/ /index.html;` und einen `/api/`-Proxy konfigurieren.

## Troubleshooting

**API-Aufrufe schlagen fehl** — läuft das Backend? `curl http://localhost:8080/health`.
Proxy in `vite.config.js` prüfen; CORS-Header setzt Caddy.

**Anmeldung schlägt fehl** — Keycloak erreichbar (`curl http://localhost:8090/realms/betting-game`)?
Werte in `.env` prüfen, Redirect-URI des Clients `betting-game-frontend` muss zur
aufgerufenen URL passen. Nach Änderungen an `.env` den Dev-Server neu starten.

**Teilnehmeransichten bleiben leer** — trägt das Token einen `participant_id`-Claim? Der
kommt aus dem Benutzerattribut im Realm.

**Alles ist leer, aber ohne Fehler** — vermutlich sind schlicht keine Daten angelegt.
[`../QUICKSTART.md`](../QUICKSTART.md) spielt ein Tippjahr von Hand durch.

**Build-Fehler** — `rm -rf node_modules dist && npm install && npm run build`

## Stack

Vue 3.4 (Composition API, `<script setup>`), Vue Router 4.2, Pinia 2.1, Axios 1.6,
keycloak-js 23, Vite 5.

## Offene Punkte

- Keine automatisierten Tests (Vitest, Playwright).
- Kein TypeScript.
- Teilnehmer- und Adminlisten arbeiten teils mit IDs statt mit Namen, weil die Basisversion
  keinen Endpunkt hat, der Teilnehmer auflistet.
