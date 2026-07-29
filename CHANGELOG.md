# Changelog

Chronik der größeren Umbauten, neueste zuerst. Der aktuelle Stand steht in
[README.md](README.md) und [ARCHITECTURE.md](ARCHITECTURE.md) – dieses Dokument hält nur
fest, was wann und warum geändert wurde.

---

## Arbeitsanleitung für Agenten (2026-07-29, `de9215b`)

[AGENTS.md](AGENTS.md) als werkzeugneutrale Projektanleitung, [CLAUDE.md](CLAUDE.md) für
das, was in dieser Arbeitsumgebung dazukommt. Enthält die Statustabelle, welche Dokumente
nach dem Kurswechsel nachgezogen sind und welche nicht.

---

## Token-Signatur wird geprüft (2026-07-29, `9378be8`)

**Vorher las die Anwendung die Claims und glaubte sie.** Jeder konnte sich eine
`participant_id` und die Rolle `admin` ausstellen; B-15 bis B-17 waren damit Dekoration.

- `TokenVerifier` prüft `alg` gegen eine Allowlist, die Signatur gegen den Public Key aus
  dem JWKS des Realms, `exp`/`nbf`/`iat` mit Leeway, `iss` exakt und optional `aud`
- `JwkSet` baut RSA-Schlüssel als PEM auf, `KeycloakKeys` holt und cacht das Key Set
  (PSR-16) und behandelt Rotation, `StaticKeys` bedient Deployments ohne Netzzugriff
- Die Allowlist **kann nur asymmetrische Verfahren enthalten** – ein `HS256` in der
  Konfiguration fällt beim Start auf statt auf dem Request, der damit gefälscht worden wäre
- Nicht erreichbares Keycloak antwortet **503, nicht 401**

Der Schlüssel kommt immer aus dem Key Set, nie aus dem Token. Eine unbekannte `kid` löst
genau einen gedrosselten Refetch aus. Details in [KEYCLOAK.md](KEYCLOAK.md).

---

## Betriebsschicht (2026-07-28, `b545ec0`)

OPS-01 bis OPS-04: Command Log, Idempotenz, Audit Trail, Projektionen.

- `command_log` mit Unique Key auf dem `Idempotency-Key`. Der Key wird beansprucht,
  **bevor** der Command läuft – erst prüfen und dann ausführen ließe ein Fenster, in dem
  zwei parallele Wiederholungen beide durchkommen
- `GET /commands/{commandId}`, `GET /admin/audit/{type}/{id}`, `GET /admin/projections`,
  `POST /admin/projections/{name}/rebuild`
- Sieben Projektoren, einer je Read Model, plus `ProjectionManager`
- `ProjectionRebuildTest` spielt ein ganzes Tippjahr durch, baut aus dem Event Store neu
  auf und vergleicht alle 13 Read-Model-Tabellen zeilenweise

Ein Rebuild ist bewusst **kein** Command: er ändert keinen Domänenzustand und gehört nicht
in die Command-Historie.

---

## Basisversion über HTTP (2026-07-28, `bd83a0d`)

Der `Kernel` übernimmt, was vorher in `public/index.php` stand: Routing,
Authentifizierung, Rollenprüfung, Fehlerabbildung. `index.php` ist nur noch die Brücke zu
den PHP-Globals – dadurch ist die ganze Kette ohne Webserver testbar.

- `ErrorMapper` als einzige Stelle, die HTTP-Codes kennt; Handler werfen Domänen-Ausnahmen
- `Authorization::requireSelf()` vergleicht die Identität **aus dem Token** mit dem Pfad,
  und zwar vor der Query – sonst verriete ein `404` bereits, dass zu einem fremden
  Teilnehmer nichts existiert
- `Input` und `Support\Row` prüfen `mixed` aus Request und Datenbank an je einer Stelle,
  statt es überall zu casten

---

## Commands und Queries für B-01 bis B-14 (2026-07-28, `444d918`)

Neun Command-Handler und zehn Query-Handler, dazu die neun Controller. Handler kennen kein
HTTP; Commands antworten mit `202`, Queries mit `200`.

`WinningsDistribution` liegt im Domain-Service, weil zwei Aufrufer dieselbe Rechnung
brauchen: der Command-Handler beim Eintragen der Gewinne und der `DrawProjector` beim
Neuaufbau. `EvenSplit` teilt Geld in ganzen Cent und legt den Rest auf den ersten Anteil –
in Fließkomma zu teilen und je Anteil zu runden vernichtet Geld.

---

## Repositories für die Lotto-Aggregate (2026-07-28, `7f4e638`)

Sieben Repositories auf der gemeinsamen Basis `EventSourcedRepository`.

- Append und Projektionsschreiben in **einer** Transaktion. Sonst bliebe nach einer vom
  Unique Key abgelehnten Reihe ein Event im Store, das keine Zeile beschreibt
- Neue Aggregate mit reinem `INSERT`, geladene mit `UPDATE` – kein
  `ON DUPLICATE KEY UPDATE`, das würde eine zweite Tippreihe für dieselbe Periode
  stillschweigend überschreiben statt den `409` auszulösen
- SQLSTATE 23000 wird zu `DuplicateEntryException`: ein abgelehnter Unique Key ist eine
  Geschäftsregel, die Nein sagt, kein Datenbankfehler

---

## Konfigurierbare Tippperiode (2026-07-28, `c554a18`)

Das feste „eine Reihe pro Tippjahr" wird zur **Tippperiode** (`BetPeriod`): ein frei
wählbarer, überlappungsfreier Zeitraum innerhalb des Tippjahres. Der Unique Key wandert von
`(participant_id, tipp_year_id)` auf `(participant_id, bet_period_id)`.

Damit ist die Periodenlänge eine Konfiguration, keine Annahme im Code. Der Grenzfall „eine
Periode = das ganze Tippjahr" reproduziert exakt das vorherige Verhalten.

---

## Schema und Domäne auf das Lotto-Modell (2026-07-28, `5f8f9ea`)

Sieben Aggregate (`TippYear`, `BetPeriod`, `BetRow`, `Ticket`, `Draw`, `Fee`,
`Participant`), 14 Events, neue Value Objects (`LottoNumbers`, `Superzahl`, `DateRange`,
`EvenSplit`, `WinningClass`, `TippYearStatus`).

Neue Tabellen: `tipp_year`, `membership`, `bet_period`, `bet_row`, `ticket`, `ticket_row`,
`draw`, `ticket_draw_result`, `ticket_row_match`, `payout`, `payout_share`. Die
Sport-Tabellen liegen als [database/schema-e2-sports.sql](database/schema-e2-sports.sql)
für E2 bereit.

---

## Kurswechsel auf die Lotterie-Tippgemeinschaft (2026-07-27, `f1d0771`)

**Die Domäne war missverstanden.** Das Projekt ist kein allgemeines Sportwetten-Tippspiel,
sondern die Verwaltung einer Lotto-6-aus-49-Tippgemeinschaft. Der Commit stellt Modell,
Stories und API-Spezifikation um und staffelt alles in eine Basisversion plus zwei
Ausbaustufen (E1 Selbstverwaltung, E2 Sportwetten).

| Bisher | Wird zu |
|---|---|
| `BettingGame` | `TippYear` |
| `GameParticipation` | `Membership` |
| `Prediction` | `BetRow` – kein `event_id`, sondern `bet_period_id`; sechs Zahlen statt freiem JSON |
| `Event` | `Draw` – kein Tippschluss, weil nicht pro Ziehung getippt wird |
| `Result` | geht in `Draw` auf |
| `ParticipantScore` | `TicketRowMatch` + `PayoutShare` |

Neu und ohne Entsprechung im alten Modell: `Ticket`, `TicketRow`, `TicketDrawResult`,
`TicketRowMatch`, `Payout`, `PayoutShare`.

**Mitgegangen:** das `demo/`-Verzeichnis (eine lauffähige Nur-Lese-Demo für Predictions und
Results) wurde entfernt; die zugehörige `DEMO.md` beschrieb danach knapp zwei Wochen lang
ein Verzeichnis, das es nicht mehr gab, und ist mit der Doku-Aktualisierung vom 2026-07-29
gelöscht worden. Die alte OpenAPI-Spezifikation liegt als
[betting_game_api_e2_sports.yaml](betting_game_api_e2_sports.yaml) für E2 bereit.

**Nicht mitgegangen:** [frontend/](frontend/) bedient weiterhin Predictions, Scores und
Games und passt zu keinem Endpunkt mehr – siehe [FRONTEND.md](FRONTEND.md).

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

**Damals offen:** `AuthMiddleware` wurde von `public/index.php` noch nicht aufgerufen, dort
lief eine Token-Simulation. Erledigt mit `bd83a0d` (Kernel) und `9378be8`
(Signaturprüfung).

---

## PSR-Standards

**Neu:** PSR-3 (Logging), PSR-11 (Container), PSR-16 (Cache) – zusätzlich zu den bereits
vorhandenen PSR-4 und PSR-12.

- `Infrastructure/Logging/LoggerFactory.php` – vier Monolog-Logger (App, Event Store,
  Error, CQRS)
- `Infrastructure/DI/PsrContainer.php` – PSR-11-Adapter um PHP-DI
- `Infrastructure/Cache/FileCache.php` und `RedisCache.php` – PSR-16 mit TTL-Support
- 4 neue Dependencies: `psr/log`, `psr/container`, `psr/simple-cache`, `monolog/monolog`
- Neuer Test: `tests/Unit/Infrastructure/FileCacheTest.php`

**Offen:** Die Anwendungslogik nutzt beides weiterhin nicht. Produktive Nutzer sind nur
`KeycloakKeys` (Cache für das JWKS, seit `9378be8`) und `AuthMiddleware` (Logger).
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

Seitdem ist die Codebasis auf **153 Dateien** unter `src/` gewachsen. Zwei Ausnahmen von
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

**Lücken der Basisversion**

- [ ] Route und Command für den Lebenszyklus des Tippjahres (`start`, `close`) — heute nur
      aus Tests erreichbar, siehe [ARCHITECTURE.md](ARCHITECTURE.md), Abschnitt 9
- [ ] Endpunkt zum Anlegen eines Teilnehmers (Selbstregistrierung ist E1-01)

**Technisch**

- [ ] `LoggerInterface` in die Command-Handler
- [ ] Read Models cachen (PSR-16 existiert), inklusive Invalidierung
- [ ] Redis-Service in `docker-compose.yml`
- [ ] Health Checks in `docker-compose.yml`, Multi-Stage Docker Build
- [ ] Event Publishing: `event_publisher` wird geschrieben, aber von niemandem geleert
- [ ] Prometheus-Metriken, Tracing, Rate Limiting

**Fachlich**

- [ ] Ausbaustufe E1 (Selbstverwaltung), Ausbaustufe E2 (Sportwetten)
- [ ] Frontend an die aktuelle API anschließen oder entfernen
