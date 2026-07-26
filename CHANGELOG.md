# Changelog

Chronik der größeren Umbauten. Der aktuelle Stand steht in [README.md](README.md) und
[ARCHITECTURE.md](ARCHITECTURE.md) – dieses Dokument hält nur fest, was wann und warum
geändert wurde.

---

## Keycloak-Integration

**Neu:** OAuth2/OIDC-Authentifizierung über Keycloak 23.

- Zwei neue Container: `keycloak` (Port 8090) und `keycloak-db` (PostgreSQL 16)
- Realm `betting-game` wird beim Start automatisch aus `keycloak/realm-export.json`
  importiert – 3 Demo-User, 2 Clients, Rollen `user`/`admin`, Custom Claim `participant_id`
- Backend: `Infrastructure/Auth/KeycloakService.php` und `AuthMiddleware.php`,
  registriert im DI-Container
- Frontend: `services/keycloak.js`, überarbeiteter Auth Store, Keycloak-Login,
  `public/silent-check-sso.html`, neue `.env`
- Konfiguration in `config/config.php` und `.env.example` erweitert

**Offen:** `AuthMiddleware` wird von `public/index.php` noch nicht aufgerufen; dort läuft
weiterhin eine Token-Simulation. Details in [KEYCLOAK.md](KEYCLOAK.md).

---

## PSR-Standards

**Neu:** PSR-3 (Logging), PSR-11 (Container), PSR-16 (Cache) – zusätzlich zu den bereits
vorhandenen PSR-4 und PSR-12.

- `Infrastructure/Logging/LoggerFactory.php` – vier Monolog-Logger (App, Event Store,
  Error, CQRS)
- `Infrastructure/DI/PsrContainer.php` – PSR-11-Adapter um PHP-DI
- `Infrastructure/Cache/FileCache.php` und `RedisCache.php` – PSR-16 mit TTL-Support
- 4 neue Dependencies: `psr/log`, `psr/container`, `psr/simple-cache`, `monolog/monolog`
- Neuer Test: `tests/Unit/Infrastructure/FileCacheTest.php` (12 Tests)

**Offen:** Logger und Cache werden von der Anwendungslogik noch nicht genutzt.
Details in [PSR.md](PSR.md).

---

## Vue.js Frontend

**Neu:** Single Page Application für die API.

- 6 Views (Login, Predictions-Liste/Neu/Bearbeiten, Scores, Games), später um 3
  Admin-Views ergänzt
- Pinia Auth Store, Axios API Client mit Interceptors, Vue Router mit Guards
- Eigener Container im Stack: Production-Build via Vite, ausgeliefert von Nginx auf Port 3000

Details in [FRONTEND.md](FRONTEND.md).

---

## One Class Per File

**Umbau:** 12 Sammel-Dateien mit je mehreren Klassen wurden auf 48 Einzeldateien
aufgeteilt. Keine funktionalen Änderungen, keine Breaking Changes – Namespaces und API
blieben identisch.

| Vorher | Nachher |
|--------|---------|
| `ValueObjects.php` | 6 Dateien in `Domain/ValueObject/` |
| `Exceptions.php` | 8 Dateien in `Domain/Exception/` |
| `PredictionEvents.php` | 3 Dateien + `DomainEvent.php` |
| `RepositoryInterfaces.php` | 4 Dateien in `Domain/Repository/` |
| `Commands.php` | 5 Dateien in `Application/Command/` |
| `CommandHandlers.php` | 2 Handler-Dateien |
| `Queries.php` | 6 Dateien in `Application/Query/` |
| `QueryHandlers.php` | 4 Dateien (Handler + Read-Model-Interfaces) |
| `Repositories.php` | 3 Dateien in `Infrastructure/Persistence/` |
| `ReadModelRepositories.php` | 2 Dateien |
| `Controllers.php` | 2 Controller-Dateien |
| `HttpHelpers.php` | `Request.php`, `JsonResponse.php` |

**Nutzen:** exakte PSR-4-Zuordnung, präzisere Diffs, schnellere IDE-Navigation, weniger
Merge-Konflikte.

**Imports** änderten sich von `use …\ValueObject\ValueObjects;` (Zugriff über
`ValueObjects\ParticipantId`) auf einzelne Imports pro Klasse.

Seitdem ist die Codebasis auf **111 Dateien** unter `src/` gewachsen. Zwei Ausnahmen von
der Regel bestehen weiterhin: `PsrContainer.php` und `FileCache.php` enthalten jeweils
zusätzlich ihre Exception-Klassen.

---

## Docker Stack v2.0 – Modernisierung

**Ersetzt:** Apache mit mod_php → Caddy 2.7 + PHP-FPM 8.3 (Alpine).
**Aktualisiert:** MariaDB 10.11 → 11.3.

Neue Dateien:

```
docker/
├── Dockerfile.php          # Custom PHP-FPM Image
├── Caddyfile               # Caddy-Konfiguration
├── php-fpm.conf            # Pool-Settings
├── php.ini                 # Runtime-Settings
├── nginx.conf.example      # Nginx-Alternative
└── apache.conf             # Apache-Beispiel (Legacy)
.dockerignore
```

Änderungen an `docker-compose.yml`: Webserver und PHP in getrennten Services, eigenes
Netzwerk, persistente Volumes für Caddy, optimierte MariaDB-Parameter.
Neue Make-Targets: `logs-php`, `logs-caddy`, `logs-db`, `build`, `fresh`, Shell-Zugriffe.

**Warum Caddy:** automatisches HTTPS, HTTP/2 und HTTP/3, einfachere Konfiguration,
eingebaute Kompression (Gzip, Zstd), Zero-Downtime-Reloads.
**Warum PHP-FPM:** deutlich kleineres Image, besseres Prozess-Management, unabhängige
Skalierung, vorkonfiguriertes OPcache.

Richtwerte aus der Umstellung (nicht nachgemessen): Image ~400 MB → ~50 MB,
RAM ~150 MB → ~80 MB, Latenz ~8 ms → ~5 ms.

**Keine Breaking Changes** – die API blieb unverändert, URLs ebenfalls
(API `:8080`, PHPMyAdmin `:8081`).

**Security:** `expose_php` deaktiviert, Alpine-Basis, Security Headers in der Caddyfile,
Netzwerk-Isolation, PHP-FPM-Worker laufen als `www-data` (der Master-Prozess läuft wie bei
PHP-FPM üblich als root).

---

## Docker Stack – Konfigurationsfehler behoben

Zwei falsch benannte Direktiven verhinderten den Start:

| Datei | Fehler | Ursache |
|-------|--------|---------|
| `docker/Caddyfile` | `unrecognized subdirective split_path` | Direktive heißt in Caddy 2 `split`, nicht `split_path` – und wird für den Standard-Front-Controller gar nicht gebraucht |
| `docker/php-fpm.conf` | `unknown entry 'process_priority'` | korrekt wäre `process.priority` (mit Punkt) |

Zusätzlich entfernt: `request_slowlog_timeout`, `slowlog`, `listen.backlog`, `access.log`,
`access.format` – allesamt gültige Direktiven, die aber ein beschreibbares
Log-Verzeichnis voraussetzen, das im Alpine-Image fehlt.

Als Fallback entstanden `docker/Caddyfile.minimal`, `docker/Caddyfile.alternative`,
`docker/php-fpm.conf.minimal` sowie die Skripte `fix-caddy.sh` und `fix-php-fpm.sh`
(Make-Targets `fix-caddy`, `fix-php-fpm`, `fix-all`).

Diagnose und Fallbacks: [DOCKER.md](DOCKER.md), Abschnitt „Troubleshooting".

---

## Geplant

- [ ] `AuthMiddleware` in `public/index.php` einbinden
- [ ] Fehlende `Persistence\PredictionRepository` ergänzen
- [ ] Container-Bindings für Admin-/Leaderboard-Interfaces
- [ ] Fehlende Admin-Routen (`scores/calculate`, `participants/{id}/scores`)
- [ ] Redis-Service in `docker-compose.yml` (PSR-16-Implementierung existiert)
- [ ] Health Checks in `docker-compose.yml`
- [ ] Multi-Stage Docker Build
- [ ] Event Publishing über Message Queue
- [ ] Prometheus-Metriken, Tracing
