# Frontend – Vue.js 3 SPA (Altbestand)

> ## ⛔ Diese SPA passt nicht mehr zum Backend
>
> Das Frontend stammt aus dem **Sportwetten-Tippspiel**, das dieses Projekt vor dem
> Kurswechsel auf die Lotterie-Tippgemeinschaft war (Commit `f1d0771`). Es ruft
> Predictions, Scores und Games auf — **keiner dieser Endpunkte existiert noch**. Jeder
> fachliche Request der SPA läuft heute in einen `404`; lediglich Login und Logout über
> Keycloak funktionieren weiter.
>
> Die aktuelle API ist in [betting_game_api.yaml](betting_game_api.yaml) beschrieben, die
> Fachlichkeit in [USER_STORIES.md](USER_STORIES.md). Für die Basisversion wird kein
> Frontend gebraucht — Teilnehmer lesen, der Administrator bucht, beides über HTTP.
>
> **Dieses Dokument beschreibt den Ist-Zustand von [frontend/](frontend/) als Altbestand.**
> Nichts daraus als Vorlage für neue Arbeit übernehmen: weder die Endpunktnamen noch die
> Views noch das API-Client-Modul. Wer die Oberfläche wiederbeleben will, baut sie gegen
> die Endpunkte aus [README.md](README.md) neu auf.
>
> Container abschalten: `docker-compose stop frontend`.

Ergänzende Setup-Hinweise stehen in [`frontend/README.md`](frontend/README.md), die
Auth-Details in [KEYCLOAK.md](KEYCLOAK.md).

## Überblick

| Metrik | Wert |
|--------|------|
| Komponenten | 9 Views + `App.vue` |
| Routen | 10 (inkl. Redirect `/` → `/predictions`) |
| Services | 3 (API Client, Keycloak Wrapper, Auth Store) |
| Dependencies | 5 production, 4 dev |

**Stack:** Vue 3.4 (Composition API, `<script setup>`), Vue Router 4.2, Pinia 2.1,
Axios 1.6, keycloak-js 23, Vite 5.

## Dateistruktur

```
frontend/
├── src/
│   ├── views/                       # 9 Page Components
│   │   ├── LoginView.vue            # Login via Keycloak
│   │   ├── PredictionsView.vue      # Liste aller Predictions
│   │   ├── NewPredictionView.vue    # Neue Prediction erstellen
│   │   ├── EditPredictionView.vue   # Prediction bearbeiten
│   │   ├── ScoresView.vue           # Scores Dashboard
│   │   ├── GamesView.vue            # Games Management
│   │   ├── AdminGamesView.vue       # Admin: Games verwalten
│   │   ├── AdminPredictionsView.vue # Admin: alle Predictions
│   │   └── AdminResultsView.vue     # Admin: Ergebnisse erfassen
│   ├── stores/auth.js               # Pinia Auth Store (Keycloak)
│   ├── services/
│   │   ├── api.js                   # Axios API Client
│   │   └── keycloak.js              # Keycloak-JS Wrapper
│   ├── router/index.js              # Vue Router + Guards
│   ├── App.vue                      # Navigation + Layout
│   └── main.js                      # Entry Point
├── public/silent-check-sso.html     # Silent SSO Check
├── .env                             # Keycloak- und API-URLs
├── vite.config.js
├── Dockerfile                       # Production Build (Nginx)
└── nginx.conf
```

## Views & Routen

| View | Route | Beschreibung |
|------|-------|--------------|
| LoginView | `/login` | Keycloak Redirect Login |
| PredictionsView | `/predictions` | Liste, Filter, Status Badges, Edit/View |
| NewPredictionView | `/predictions/new` | JSON Editor, Quick Templates, Validation |
| EditPredictionView | `/predictions/:id/edit` | Current vs. Updated, Change Detection |
| ScoresView | `/scores` | Summary Dashboard + Score History |
| GamesView | `/games` | Join/Leave, aktive und vergangene Teilnahmen |
| AdminGamesView | `/admin/games` | Games anlegen, beenden, Übersicht |
| AdminPredictionsView | `/admin/predictions` | alle Predictions einsehen |
| AdminResultsView | `/admin/results` | Event-Ergebnisse erfassen |

`/` leitet auf `/predictions` um. Routen mit `requiresAuth` verlangen einen Login,
`/admin/*` zusätzlich `requiresAdmin` – ohne Admin-Rolle geht es zurück auf `/predictions`.

```javascript
router.beforeEach((to, from, next) => {
  const authStore = useAuthStore()

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    next('/login')
  } else if (to.meta.requiresAdmin && !authStore.isAdmin()) {
    next('/predictions')
  } else if (to.path === '/login' && authStore.isAuthenticated) {
    next('/predictions')
  } else {
    next()
  }
})
```

## API-Integration

```
Vue Component → API Service (axios) → Interceptor (Token) →
Vite Proxy (/api → :8080) → Backend → Interceptor (Fehler) → Vue Component
```

Proxy-Konfiguration in `vite.config.js`: `/api` → `http://localhost:8080`, Präfix wird
entfernt. Der Dev-Server läuft auf Port 3000.

```javascript
// Participant
api.getPredictions(participantId, params)
api.getPrediction(participantId, predictionId)
api.submitPrediction(participantId, eventId, predictionData)
api.updatePrediction(participantId, predictionId, predictionData)
api.getScores(participantId, bettingGameId)
api.getParticipations(participantId, status)
api.joinGame(participantId, bettingGameId, acceptTerms)
api.leaveGame(participantId, bettingGameId)

// Admin - unter api.admin.*
api.admin.getAllPredictions(params)
api.admin.getAllGames(params)
api.admin.createGame(gameData)
api.admin.endGame(bettingGameId, data)
api.admin.recordResult(eventId, resultData)
api.admin.calculateScores(eventId)
api.admin.awardScore(participantId, data)
```

> ⛔ **Keine dieser Methoden trifft heute noch eine Route.** Sie rufen
> `/participants/{id}/predictions`, `/participants/{id}/scores`, `/admin/games`,
> `/admin/events/{id}/results` und Ähnliches auf — die Routentabelle in
> [Router.php](src/Presentation/Router/Router.php) kennt seit dem Kurswechsel nur noch
> Tippreihen, Tippscheine, Ziehungen, Gebühren und die Ausschüttung. Alle Aufrufe enden in
> `404 Route not found`.
>
> Die Entsprechungen in der Lotterie-Domäne sind strukturell andere: ein Tipp ist dort
> `(Teilnehmer, Tippperiode)` statt `(Teilnehmer, Ereignis)`, und Punkte gibt es nicht,
> sondern Treffer je Reihe und einen gleichmäßigen Jahresanteil. Ein neues Frontend lässt
> sich deshalb nicht aus diesem ableiten — siehe
> [USER_STORIES.md](USER_STORIES.md), Abschnitt „Migration des bestehenden Codes".

## Authentifizierung

Login läuft vollständig über Keycloak (OAuth2/OIDC mit PKCE). Tokens liegen ausschließlich
im Speicher des Keycloak-JS-Adapters, **nicht** im localStorage.

```javascript
// Initialisierung beim App-Start (main.js)
await authStore.initKeycloak()

await authStore.login()      // Redirect zur Keycloak Login-Seite
await authStore.logout()     // Keycloak Logout + lokalen State leeren

keycloakService.onTokenExpired(() => keycloakService.updateToken(30))
```

Der Request-Interceptor hängt den aktuellen Token an, der Response-Interceptor leitet bei
`401` zum Login. Details und Demo-Benutzer: [KEYCLOAK.md](KEYCLOAK.md).

## Design System

```css
--blue:     #2563eb;   /* Primary Actions */
--green:    #10b981;   /* Success */
--yellow:   #f59e0b;   /* Warnings */
--red:      #ef4444;   /* Errors, Danger */
--gray-900: #1f2937;   /* Headings */
--gray-600: #6b7280;   /* Body Text */
--gray-300: #d1d5db;   /* Borders */
--gray-100: #f3f4f6;   /* Backgrounds */
```

**Komponenten:** Cards (`.prediction-card`, `.game-card`, `.score-card`, `.summary-card`),
Buttons (`.btn-primary`, `.btn-secondary`, `.btn-danger`, `.btn-cancel`), Status Badges
(`.status.submitted|pending|evaluated`), Modal Dialogs, Loading/Empty/Error States.

**Responsive:** Mobile First, Grid mit `repeat(auto-fill, minmax(350px, 1fr))` – 1 Spalte
mobil, 2 auf Tablets, 3 auf Desktop. Scoped Styles pro Komponente.

## Entwicklung

```bash
cd frontend
npm install
npm run dev        # http://localhost:3000 (Backend muss auf :8080 laufen)
npm run build      # Ausgabe nach dist/
npm run lint
```

Läuft parallel der Frontend-Container, belegt dieser Port 3000 – vorher
`docker-compose stop frontend`.

### Deployment

```bash
docker-compose build && docker-compose up -d
# Frontend :3000 | API :8080 | PHPMyAdmin :8081 | Keycloak :8090
```

Statisches Hosting (Netlify, Vercel): `npm run build`, dann `dist/` deployen.
Bei manuellem Nginx zusätzlich `try_files $uri $uri/ /index.html;` und einen
`/api/`-Proxy auf das Backend konfigurieren.

## Testing

Automatisierte Tests existieren nicht. Manuelle Checkliste:

- [ ] Login über Keycloak, Logout, Redirect auf `/login` ohne Session
- [ ] Session bleibt nach Reload erhalten (Silent SSO)
- [ ] `/admin/*` nur mit Admin-Rolle erreichbar
- [ ] Predictions: Liste, Filter, Anlegen, Bearbeiten, Sperre nach Deadline
- [ ] JSON-Validierung und Templates
- [ ] Scores: Summen und History korrekt
- [ ] Games: Join, Leave-Bestätigung, vergangene Teilnahmen
- [ ] Responsive Layout, Loading- und Fehlerzustände

## Performance

Zielwerte aus der Entwurfsphase (FCP < 1 s, TTI < 2 s, Lighthouse 95+) wurden nicht
gemessen. Ist-Werte über `npm run build` und Lighthouse ermitteln.

Aktive Optimierungen: Code Splitting über Lazy-Route-Imports, Tree Shaking, Minification,
Gzip, Asset Caching.

## Troubleshooting

**API-Calls schlagen fehl** – läuft das Backend auf 8080 (`curl http://localhost:8080/health`)?
Proxy in `vite.config.js` prüfen; CORS-Header setzt Caddy.

**Login funktioniert nicht** – siehe [KEYCLOAK.md](KEYCLOAK.md), Abschnitt „Troubleshooting".

**Predictions bleiben leer** – stimmt die `participant_id` im Token? Response im
Network-Tab prüfen; ggf. Testdaten anlegen (siehe [QUICKSTART.md](QUICKSTART.md)).

**Frontend-Container startet nicht**

```bash
docker-compose build frontend --no-cache
docker-compose logs frontend
```

**Build-Fehler** – `rm -rf node_modules dist && npm install && npm run build`

## Offene Punkte

Vor allem anderen: **die SPA an die aktuelle API anschließen oder sie entfernen.** Solange
beides nicht passiert, ist sie ein Container, der ein Produkt zeigt, das es nicht mehr gibt.

Was ein Frontend der Basisversion abbilden müsste (alles lesend, Schreibzugriff hat nur der
Administrator):

| Ansicht | Endpunkt |
|---|---|
| Eigene Tippreihe | `GET /participants/{id}/bet-row` |
| Eigene Teilnahmen | `GET /participants/{id}/memberships` |
| Eigene Gebühren | `GET /participants/{id}/fees` |
| Eigener Jahresanteil | `GET /participants/{id}/payout-share` |
| Ziehungen des Tippjahres | `GET /tipp-years/{id}/draws` |

Danach erst: Unit-/E2E-Tests (Vitest, Playwright), TypeScript, Dark Mode,
Mehrsprachigkeit, wiederverwendbare Komponenten.
