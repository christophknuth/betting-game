# Betting Game Frontend

Vue.js 3 Frontend für die Betting Game API.

> Ausführliche Dokumentation (Views, Routen, API-Client, Design System, Troubleshooting):
> [`../FRONTEND.md`](../FRONTEND.md). Authentifizierung: [`../KEYCLOAK.md`](../KEYCLOAK.md).

## 🚀 Features

- ✅ **Keycloak Login** - OAuth2/OIDC mit PKCE und Token-Auto-Refresh
- ✅ **Predictions Management** - Erstellen, Bearbeiten, Anzeigen von Predictions
- ✅ **Scores Dashboard** - Übersicht über Points und Prize Money
- ✅ **Games Participation** - Games beitreten und verlassen
- ✅ **Admin-Bereich** - Games, Predictions und Ergebnisse (Rolle `admin`)
- ✅ **Responsive Design** - Mobile-friendly UI
- ✅ **Vue 3 Composition API** - Moderne Vue.js Features
- ✅ **Pinia State Management** - Für Auth und globalen State
- ✅ **Vue Router** - Navigation mit Guards
- ✅ **Axios HTTP Client** - API Integration mit Interceptors

## 📋 Requirements

- Node.js 18+ 
- npm oder yarn
- Betting Game API running auf `http://localhost:8080`
- Keycloak running auf `http://localhost:8090` (Realm `betting-game`)

## 🔧 Installation

### 1. Dependencies installieren

```bash
cd frontend
npm install
```

### 2. Development Server starten

```bash
npm run dev
```

Frontend läuft auf: **http://localhost:3000**

### 3. Production Build

```bash
npm run build
```

Build Ausgabe in `dist/` Verzeichnis.

## 📁 Projektstruktur

```
frontend/
├── src/
│   ├── views/               # Page Components
│   │   ├── LoginView.vue
│   │   ├── PredictionsView.vue
│   │   ├── NewPredictionView.vue
│   │   ├── EditPredictionView.vue
│   │   ├── ScoresView.vue
│   │   ├── GamesView.vue
│   │   ├── AdminGamesView.vue
│   │   ├── AdminPredictionsView.vue
│   │   └── AdminResultsView.vue
│   ├── stores/              # Pinia Stores
│   │   └── auth.js
│   ├── services/            # API & Auth Services
│   │   ├── api.js
│   │   └── keycloak.js
│   ├── router/              # Vue Router
│   │   └── index.js
│   ├── App.vue              # Main App Component
│   └── main.js              # Entry Point
├── public/
│   └── silent-check-sso.html
├── .env                     # Keycloak- und API-URLs
├── index.html
├── vite.config.js
└── package.json
```

## 🎯 Features im Detail

### Authentication

**Keycloak (OAuth2/OIDC):**
- Login über Redirect zur Keycloak Login-Seite
- `participant_id`, Username, E-Mail und Rollen kommen als Claims aus dem JWT
- Token liegt im Speicher (Keycloak-JS) und wird automatisch erneuert
- Silent SSO über `public/silent-check-sso.html`

Demo-Benutzer aus `keycloak/realm-export.json`:

```
admin    / admin123   (Rollen: user, admin, participant_id 1)
testuser / test123    (Rolle:  user,        participant_id 2)
john.doe / password   (Rolle:  user,        participant_id 3)
```

### Predictions

**Übersicht:**
- Liste aller Predictions
- Filter nach Status (submitted, pending, evaluated)
- Status-Badge für jede Prediction
- Edit-Button wenn noch editierbar

**Neue Prediction:**
- Event ID eingeben
- Prediction Data als JSON
- Quick Templates für häufige Formate
- JSON Validation

**Prediction bearbeiten:**
- Nur möglich vor Deadline
- Current vs. Updated Data
- JSON Editor mit Validation

### Scores

**Dashboard:**
- Summary Card mit:
  - Total Points
  - Total Prize Money
  - Games Participated
- Score History mit Details

### Games

**Participation Management:**
- Active Games anzeigen
- Join Game mit Game ID
- Terms & Conditions Checkbox
- Leave Game mit Confirmation Modal
- Past Participations History

## 🔌 API Integration

### Proxy Configuration

Vite Proxy in `vite.config.js`:

```javascript
server: {
  proxy: {
    '/api': {
      target: 'http://localhost:8080',
      changeOrigin: true,
      rewrite: (path) => path.replace(/^\/api/, '')
    }
  }
}
```

API Calls:
```
Frontend: http://localhost:3000/api/...
→ Proxy zu: http://localhost:8080/...
```

### API Endpoints

```javascript
// Predictions
api.getPredictions(participantId, params)
api.submitPrediction(participantId, eventId, predictionData)
api.updatePrediction(participantId, predictionId, predictionData)

// Scores
api.getScores(participantId, bettingGameId)

// Games
api.getParticipations(participantId, status)
api.joinGame(participantId, bettingGameId, acceptTerms)
api.leaveGame(participantId, bettingGameId)

// Admin (Rolle "admin") - unter api.admin.*
api.admin.getAllPredictions(params)
api.admin.getAllGames(params)
api.admin.createGame(gameData)
api.admin.endGame(bettingGameId, data)
api.admin.recordResult(eventId, resultData)
api.admin.calculateScores(eventId)      // ⚠️ Route existiert im Backend noch nicht
api.admin.awardScore(participantId, data) // ⚠️ Route existiert im Backend noch nicht
```

> ⚠️ `calculateScores` und `awardScore` rufen `/admin/events/{id}/scores/calculate` bzw.
> `/admin/participants/{id}/scores` auf. Diese Routen sind in
> `src/Presentation/Router/Router.php` nicht registriert und liefern derzeit 404 – die
> zugehörigen Commands (`CalculateScoresCommand`, `AwardScoreCommand`) existieren aber bereits.

## 🎨 UI/UX

### Design System

**Farben:**
- Primary: `#2563eb` (Blue)
- Success: `#10b981` (Green)
- Warning: `#f59e0b` (Yellow)
- Danger: `#ef4444` (Red)
- Gray Scale: `#1f2937` → `#f9fafb`

**Komponenten:**
- Cards mit Shadow & Hover Effects
- Gradient Summary Cards
- Modal Dialogs
- Form Validation
- Status Badges
- Empty States

### Responsive Design

- Mobile First Approach
- Grid Layout mit `auto-fill`
- Flexible Navigation
- Touch-friendly Buttons

## 🔐 Security

### Authentication

Der Request-Interceptor holt den aktuellen Token vom Keycloak-Service, erneuert ihn bei
Bedarf und setzt ihn als `Authorization: Bearer <token>`. Der Token wird bewusst **nicht**
im localStorage abgelegt (XSS-Schutz).

### Auto-Logout

Bei einer `401`-Antwort wird der lokale Auth-State geleert und der Benutzer zum
Keycloak-Login weitergeleitet.

## 🧪 Development

### Linting

```bash
npm run lint
```

### Build Optimierung

```bash
npm run build
```

Optimierte Production Build:
- Code Splitting
- Tree Shaking
- Minification
- Gzip Compression

## 📦 Deployment

### Static Hosting (Netlify, Vercel)

```bash
# Build
npm run build

# Deploy dist/ folder
```

### Docker

```dockerfile
FROM node:18-alpine AS builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM nginx:alpine
COPY --from=builder /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/nginx.conf
EXPOSE 80
```

### Environment Variables

Für Production mit echter API URL:

```javascript
// vite.config.js
export default defineConfig({
  server: {
    proxy: {
      '/api': {
        target: process.env.VITE_API_URL || 'http://localhost:8080',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/api/, '')
      }
    }
  }
})
```

## 🐛 Troubleshooting

### API Verbindung funktioniert nicht

1. Backend läuft auf Port 8080?
   ```bash
   curl http://localhost:8080/health
   ```

2. CORS aktiviert im Backend?
   - Caddy setzt CORS Headers automatisch

3. Proxy funktioniert?
   - Check Browser Console
   - Check Network Tab

### Login funktioniert nicht

1. Läuft Keycloak? `curl http://localhost:8090/realms/betting-game`
2. Werte in `.env` prüfen (`VITE_KEYCLOAK_URL`, `VITE_KEYCLOAK_REALM`, `VITE_KEYCLOAK_CLIENT_ID`)
3. Redirect-URI des Clients `betting-game-frontend` muss zur aufgerufenen URL passen
4. Nach Änderungen an `.env`: Dev-Server neu starten

### Build Errors

```bash
# Clear cache
rm -rf node_modules dist
npm install
npm run build
```

## 📚 Technologie Stack

- **Vue.js 3.4** - Progressive Framework
- **Vue Router 4.2** - Routing
- **Pinia 2.1** - State Management
- **Axios 1.6** - HTTP Client
- **keycloak-js 23.0** - OAuth2/OIDC Adapter
- **Vite 5.0** - Build Tool
- **ES Modules** - Modern JavaScript

## 🎯 Next Steps

### Geplante Features

- [ ] Real-time Updates (WebSocket)
- [ ] Notifications System
- [ ] Advanced Filters & Search
- [ ] Data Visualization (Charts)
- [ ] Export Functionality (CSV, PDF)
- [ ] Dark Mode
- [ ] Multi-Language Support

### Performance Optimierungen

- [ ] Lazy Loading für Routes
- [ ] Virtual Scrolling für lange Listen
- [ ] Image Optimization
- [ ] Service Worker (PWA)
- [ ] Caching Strategy

## 📄 License

Same as Backend - see main README.md

## 🤝 Contributing

1. Fork the repository
2. Create feature branch
3. Commit changes
4. Push to branch
5. Create Pull Request

---

**Happy Betting! 🎯**
