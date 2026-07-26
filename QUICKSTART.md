# Quick Start Guide - Betting Game (Backend + Frontend)

## 🚀 Schnellstart mit Docker (empfohlen)

> **ℹ️ Hinweis:** Beide Config-Fehler (Caddy & PHP-FPM) wurden behoben.  
> Siehe [DOCKER.md](DOCKER.md), Abschnitt „Troubleshooting", für Details.

### Stack
- **Backend:**
  - PHP-FPM 8.3 (Alpine - minimaler Footprint)
  - Caddy 2.7 (Moderner Webserver mit Auto-HTTPS)
  - MariaDB 11.3 (Neueste stabile Version)
  - PHPMyAdmin (Datenbank-Management)
- **Frontend:**
  - Vue.js 3 (Progressive Framework)
  - Vite 5 (Build Tool)
  - Nginx (Static File Server)
- **Authentifizierung:**
  - Keycloak 23.0 (OAuth2/OIDC)
  - PostgreSQL 16 (Keycloak-Datenbank)

### 1. In das Projektverzeichnis wechseln
```bash
cd betting-game
```

### 2. Mit Docker starten
```bash
make start
# Wartet automatisch 5 Sekunden, dann:
make composer-install
```

Das war's! Die Services laufen jetzt:
- **Frontend**: http://localhost:3000
- **Backend API**: http://localhost:8080
- **PHPMyAdmin**: http://localhost:8081 (root/secret)
- **Keycloak**: http://localhost:8090 (Admin Console: /admin, admin/admin)

> Keycloak braucht beim ersten Start 30–60 Sekunden für den Realm-Import.
> Fortschritt: `docker-compose logs -f keycloak`

### 3. Frontend nutzen

1. Öffne http://localhost:3000
2. Klick auf "Login with Keycloak"
3. Demo-Login: `testuser` / `test123` (Admin: `admin` / `admin123`)
4. Fertig! 🎉

Details zu Benutzern und Rollen: siehe [KEYCLOAK.md](KEYCLOAK.md).

## 🎯 Features testen

### Predictions erstellen
1. Gehe zu "New Prediction"
2. Event ID eingeben (z.B. 100)
3. JSON Template nutzen oder eigene Daten
4. Submit!

### Scores anschauen
- Gehe zu "Scores"
- Siehe Summary Dashboard
- Siehe Score History

### Games beitreten
1. Gehe zu "Games"
2. Game ID eingeben (z.B. 1)
3. Terms akzeptieren
4. Join!

## 🧪 API direkt testen

```bash
# API Health Check
curl http://localhost:8080/health

# Prediction erstellen (benötigt Auth Header)
curl -X POST http://localhost:8080/participants/1/events/1/predictions \
  -H "Authorization: Bearer test-token" \
  -H "Content-Type: application/json" \
  -d '{"predictionData": {"homeScore": 2, "awayScore": 1}}'
```

> ⚠️ `test-token` funktioniert nur, weil `public/index.php` die Token-Prüfung aktuell noch
> simuliert (jeder Bearer-Token wird akzeptiert). Einen echten Keycloak-Token holst du dir
> mit dem Snippet in [KEYCLOAK.md](KEYCLOAK.md#testen).

## 📋 Manuelle Installation (ohne Docker)

### Voraussetzungen
- PHP 8.3+
- MariaDB 10.6+ (Docker-Stack nutzt 11.3) oder MySQL 8.0+
- Composer 2.x
- Node.js 18+ (für das Frontend)

### Installation

1. **Dependencies installieren**
```bash
composer install
```

2. **Datenbank erstellen**
```bash
mysql -u root -p -e "CREATE DATABASE betting_game CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p betting_game < database/schema.sql
```

3. **Konfiguration anpassen**
```bash
cp .env.example .env
# Bearbeite .env mit deinen Datenbankzugangsdaten
```

4. **Web Server konfigurieren**

**Apache**: `.htaccess` ist bereits vorhanden

**Nginx**: Füge zu deiner config hinzu:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

5. **Starten**
```bash
# Mit PHP Built-in Server (nur Development!)
php -S localhost:8080 -t public
```

## 🧪 Tests ausführen

```bash
# Alle Tests
make test

# Mit Coverage Report
make coverage

# Statische Analyse
make phpstan

# Code Style Check
make cs-check
```

## 📊 Projektstruktur verstehen

```
betting-game/
├── src/
│   ├── Domain/           # ← Business Logik (keine Dependencies)
│   ├── Application/      # ← Use Cases (Commands & Queries)
│   ├── Infrastructure/   # ← Datenbank, EventStore, Auth, Cache, Logging
│   └── Presentation/     # ← HTTP Controller, Routing
├── tests/                # ← Unit Tests
├── database/             # ← SQL Schema
├── docker/               # ← Dockerfile, Caddyfile, PHP-Configs
├── keycloak/             # ← Realm-Export (Benutzer, Clients, Rollen)
├── frontend/             # ← Vue.js 3 SPA
└── public/               # ← Web Root
    └── index.php         # ← Entry Point
```

## 🔑 API Authentifizierung

Für Entwicklung: Jeder Authorization Header wird derzeit akzeptiert:
```bash
Authorization: Bearer test-token
```

Grund: `public/index.php` enthält noch eine Simulation der Token-Prüfung. Die echte
Validierung (`Infrastructure\Auth\KeycloakService` + `AuthMiddleware`) ist implementiert,
aber noch nicht im Entry Point verdrahtet – vor einem Production-Deployment nachziehen.

## 📝 Erste Schritte

### 1. Testdaten anlegen

```sql
-- Über PHPMyAdmin oder MySQL CLI
USE betting_game;

-- User anlegen
INSERT INTO user (username, password_hash, email) 
VALUES ('testuser', '$2y$10$...', 'test@example.com');

-- Participant anlegen
INSERT INTO participant (user_id, display_name) 
VALUES (1, 'Test Player');

-- Game Type bereits vorhanden (siehe schema.sql)

-- Betting Game anlegen
INSERT INTO betting_game (name, description, game_type_id, start_date, end_date, status)
VALUES ('Bundesliga Tipprunde', 'Tippe die Bundesliga Spiele', 1, '2024-01-01', '2024-12-31', 'active');

-- Event anlegen
INSERT INTO event (betting_game_id, event_name, event_date, deadline)
VALUES (1, 'Bayern vs Dortmund', '2024-12-31 15:30:00', '2024-12-31 15:00:00');
```

### 2. API Testen

**Prediction abrufen (noch keine vorhanden)**
```bash
curl http://localhost:8080/participants/1/predictions \
  -H "Authorization: Bearer test-token"
```

**Prediction erstellen**
```bash
curl -X POST http://localhost:8080/participants/1/events/1/predictions \
  -H "Authorization: Bearer test-token" \
  -H "Content-Type: application/json" \
  -d '{
    "predictionData": {
      "homeScore": 3,
      "awayScore": 2
    }
  }'
```

**Prediction updaten**
```bash
curl -X PUT http://localhost:8080/participants/1/predictions/{predictionId} \
  -H "Authorization: Bearer test-token" \
  -H "Content-Type: application/json" \
  -d '{
    "predictionData": {
      "homeScore": 2,
      "awayScore": 1
    }
  }'
```

## 🔍 Event Sourcing verstehen

Alle Änderungen werden als Events gespeichert:

```sql
-- Events anschauen
SELECT * FROM event_store ORDER BY event_store_id DESC LIMIT 10;

-- Aktuellen Stream Status
SELECT * FROM event_stream;

-- Projection Status
SELECT * FROM projection_state;
```

### Event Flow:
1. Command → Handler
2. Domain Logic → Events generieren
3. Events → EventStore
4. EventStore → Projections (Read Models)
5. Client erhält Response

## 🐛 Debugging

### Logs anschauen (Docker)
```bash
make logs
```

### Datenbank überprüfen
```bash
make db-shell
# Dann SQL Commands
```

### PHP Errors
```bash
# In public/index.php (Development)
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## 📚 Weitere Informationen

- Vollständige Dokumentation: [README.md](README.md)
- Architektur & offene Punkte: [ARCHITECTURE.md](ARCHITECTURE.md)
- Keycloak-Login & Demo-User: [KEYCLOAK.md](KEYCLOAK.md)
- Frontend: [FRONTEND.md](FRONTEND.md)
- Docker-Stack & Troubleshooting: [DOCKER.md](DOCKER.md)
- PSR-Standards: [PSR.md](PSR.md)
- Änderungshistorie: [CHANGELOG.md](CHANGELOG.md)
- OpenAPI Spec: `betting_game_api.yaml`
- ER-Diagramm: `betting_game_er_extended.mermaid`

## ⚡ Performance Tipps

### Production Setup

1. **OPCache aktivieren** (php.ini):
```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
```

2. **DI Container kompilieren**:
```php
// config/config.php
'production' => true,
```

3. **MariaDB optimieren**:
```sql
SET GLOBAL innodb_buffer_pool_size = 1G;
```

4. **Projections cachen**:
Nutze Redis oder Memcached für Read Models.

## 🆘 Häufige Probleme

### "Connection refused" zu Datenbank
```bash
# Docker läuft?
docker-compose ps

# Restart
make restart
```

### "Permission denied" Fehler
```bash
chmod -R 775 var/
chown -R www-data:www-data /var/www/betting-game
```

### Tests schlagen fehl
```bash
# Dependencies neu installieren
rm -rf vendor/
composer install

# Test DB erstellen
mysql -u root -p -e "CREATE DATABASE betting_game_test;"
mysql -u root -p betting_game_test < database/schema.sql
```

## 📞 Support

Bei Fragen:
1. Checke `README.md` für Details
2. Schaue in die Tests für Beispiele
3. Prüfe Event Store Logs
4. Erstelle ein GitHub Issue

Viel Erfolg! 🎉
