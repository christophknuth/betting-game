# Lotterie-Tippgemeinschaft — API

Backend-API zur Verwaltung einer **Tippgemeinschaft für Lotto 6 aus 49**.
PHP 8.3, kein Framework, Onion Architecture mit Event Sourcing und CQRS, MariaDB,
Authentifizierung über Keycloak (OIDC).

**Ausbaustufe Basis:** Teilnehmer lesen ausschließlich ihre eigenen Daten, der
Administrator bucht alles. Die Ausbaustufen E1 (Selbstverwaltung) und E2 (Sportwetten)
sind spezifiziert, aber nicht implementiert — siehe [USER_STORIES.md](USER_STORIES.md).

## Dokumentation

| Dokument | Inhalt |
|---|---|
| [USER_STORIES.md](USER_STORIES.md) | **Fachliche Referenz.** Domäne, Stories, Akzeptanzkriterien, Umsetzungsstand |
| [AGENTS.md](AGENTS.md) | Arbeitsanleitung für Entwickler und KI-Agenten |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Schichten, Muster, Klassenlandkarte, offene Punkte |
| [KEYCLOAK.md](KEYCLOAK.md) | Authentifizierung, Demo-Benutzer, Tokens |
| [DOCKER.md](DOCKER.md) | Docker-Stack, Konfiguration, Troubleshooting |
| [QUICKSTART.md](QUICKSTART.md) | Erste Schritte, ein Tippjahr von Hand durchspielen |
| [PSR.md](PSR.md) | PSR-Standards: Stand und Verwendung |
| [CHANGELOG.md](CHANGELOG.md) | Chronik der größeren Umbauten |

Maschinenlesbar: [betting_game_api.yaml](betting_game_api.yaml) (OpenAPI 3.0.3, v2.2.0),
[betting_game_er_extended.mermaid](betting_game_er_extended.mermaid),
[database/schema.sql](database/schema.sql).

## Die Domäne in fünf Sätzen

- Ein **Tippjahr** (`TippYear`) ist ein frei definierter Zeitraum, kein Kalenderjahr.
- Es zerfällt in überlappungsfreie **Tippperioden** (`BetPeriod`) — deren Länge ist
  Konfiguration, keine Annahme im Code. Eine Periode über das ganze Jahr ist zulässig.
- Jeder Teilnehmer hat **pro Periode genau eine Tippreihe** (`BetRow`) aus sechs Zahlen.
- Monatlich reicht die Gemeinschaft einen gemeinsamen **Tippschein** (`Ticket`) ein: einen
  Snapshot aller gültigen Reihen. Er erzeugt je Teilnehmer eine **Gebühr** (`Fee`).
- **Ziehungen** (`Draw`) erzeugen Gewinne für den Schein als Ganzes; sie werden über das
  Jahr gesammelt und am Jahresende **gleichmäßig auf alle Teilnehmer** ausgeschüttet.

## Architektur

### Schichten

```
Presentation    Controller, Router, Kernel, HTTP-Helfer
     ↓
Application     Commands + Handler, Queries + Handler, Projection-Manager
     ↓
Domain          Aggregate, Value Objects, Events, Repository-Interfaces
     ↑
Infrastructure  implementiert die Domain-Interfaces (PDO, EventStore, Auth, Cache)
```

`src/Domain/` hat keine Abhängigkeit nach außen — kein PDO, kein HTTP, keine PSR-Pakete.
Die Abhängigkeit zeigt immer nach innen; Infrastructure erfüllt die Interfaces, die die
Domäne vorgibt.

### Request-Fluss

```
public/index.php          Globals → Request-Objekt, Container bauen
  └─ Kernel::handle()     src/Presentation/Http/Kernel.php
       ├─ Router          FastRoute
       ├─ AuthMiddleware  außer bei 'public' => true; JWT gegen das JWKS des Realms geprüft
       ├─ Authorization   bei 'role' => 'admin'
       ├─ command_log     bei 'command' => true (Idempotency-Key, OPS-01/OPS-02)
       ├─ Controller      Input::* validiert, Command/Query-DTO, Handler
       └─ ErrorMapper     Domain-Exception → HTTP-Status
```

Der `Kernel` ist ohne Webserver testbar; `index.php` ist nur die Brücke zu den PHP-Globals.
Eine Route ist **per Default authentifiziert** — ein vergessenes Flag macht sie nicht
versehentlich öffentlich.

### Event Sourcing und CQRS

- **Schreibweg:** Handler lädt das Aggregat → Domänenlogik → das Aggregat zeichnet Events
  auf → das Repository schreibt Events **und** Projektion in **einer** Transaktion unter
  Optimistic Locking.
- **Leseweg:** Query-Handler lesen direkt die Projektionstabellen. Keine Events, keine
  Joins über den Event Store.
- **Zwei Wege zu denselben Tabellen:** Repositories schreiben ihre Projektion *synchron*
  beim Speichern. Die sieben Projektoren in `src/Infrastructure/Projection/` sind der
  zweite Weg — sie spielen das Event-Log nach (`POST /admin/projections/{name}/rebuild`).
  Dass beide dieselben Zeilen erzeugen, prüft
  [ProjectionRebuildTest](tests/Integration/Application/ProjectionRebuildTest.php) über alle
  13 Read-Model-Tabellen hinweg.
- **Ehrlich zur Asynchronität:** Die API beschreibt Commands als asynchron (`202`), die
  Implementierung schreibt synchron. Bei Ankunft der `202` ist der Command bereits
  `completed`, `projectionsUpToDate` ist immer `true`.

### Fehlerabbildung

Handler werfen Domänen-Ausnahmen und kennen kein HTTP.
[ErrorMapper](src/Presentation/Http/ErrorMapper.php) ist die einzige Übersetzungsstelle:

| Ausnahme | Status |
|---|---|
| `UnauthorizedAccessException` | 403 |
| `EntityNotFoundException` | 404 |
| `InvalidArgumentException`, `InvalidInputException` | 400 |
| `ConcurrencyException` | 409 |
| `BusinessRuleViolationException` (inkl. `DuplicateEntryException`) | 409 |
| alles andere | 500 (Meldung nur im Debug-Modus) |

Ein abgelehnter Unique Key ist eine Geschäftsregel, die Nein sagt — kein Datenbankfehler.
`EventSourcedRepository` übersetzt SQLSTATE 23000 deshalb in `DuplicateEntryException`.

## Endpunkte

22 Routen. Die Story-IDs verweisen auf [USER_STORIES.md](USER_STORIES.md).

### Teilnehmer — nur lesend

| Endpunkt | Story |
|---|---|
| `GET /participants/{id}/bet-row` | B-01 eigene Tippreihe |
| `GET /participants/{id}/memberships` | B-02 eigene Teilnahmen |
| `GET /participants/{id}/fees` | B-03 eigene Gebühren |
| `GET /participants/{id}/payout-share` | B-04 eigener Anteil an der Jahresausschüttung |
| `GET /tipp-years/{id}/draws` | B-05 Gewinn des Tippscheins je Ziehung |

Die Identität kommt aus dem Token, nie aus dem Pfad. `Authorization::requireSelf()` lehnt
fremde `participantId` mit `403` ab — auch für einen Admin, der dafür eigene Endpunkte hat.

### Administrator

| Endpunkt | Story |
|---|---|
| `PUT /admin/participants/{id}/bet-row` | B-06 Tippreihe zuordnen |
| `GET /admin/fees` | B-07 Gebührenlage |
| `PUT /admin/fees/{feeId}/payment` | B-07 Zahlungsstatus setzen |
| `POST /admin/draws` | B-08 Ziehung eintragen |
| `PUT /admin/draws/{drawId}/winnings` | B-09 Gewinne einer Ziehung eintragen |
| `GET` / `POST /admin/tipp-years` | B-10 Tippjahre |
| `GET` / `POST /admin/tipp-years/{id}/bet-periods` | B-14 Tippperioden |
| `POST /admin/tipp-years/{id}/members` | B-11 Teilnehmer aufnehmen |
| `POST /admin/tipp-years/{id}/tickets` | B-12 Tippschein einreichen |
| `POST /admin/tipp-years/{id}/payout` | B-13 Jahresausschüttung buchen |

### Betrieb

| Endpunkt | Story |
|---|---|
| `GET /commands/{commandId}` | OPS-01 Verarbeitungsstand eines Commands |
| `GET /admin/audit/{type}/{id}` | OPS-03 Event-Historie eines Aggregats |
| `GET /admin/projections` | OPS-04 Projektionen überwachen |
| `POST /admin/projections/{name}/rebuild` | OPS-04 Projektion neu aufbauen |

`GET /commands/{commandId}` ist bewusst nicht admin-geschützt: wer den Command abgesetzt
hat, darf nachsehen, und die UUID kann niemand raten.

### Öffentlich

`GET /health` — der einzige Endpunkt ohne Authentifizierung. Ein Health Check hinter einem
Token kann einem Load Balancer nicht sagen, ob der Dienst läuft. Er steht deshalb auch
nicht in der OpenAPI-Spezifikation (19 Pfade, 21 Operationen).

## Authentifizierung

Die API erwartet ein Bearer-Token von Keycloak:

```http
Authorization: Bearer <jwt>
```

Geprüft wird in dieser Reihenfolge: `alg` gegen eine Allowlist, die nur asymmetrische
Verfahren enthalten kann → Signatur gegen den Public Key aus dem JWKS des Realms →
`exp`/`nbf`/`iat` mit Leeway → `iss` exakt → `aud`, wenn konfiguriert.

- `participant_id` (Custom Claim) — für die Teilnehmer-Endpunkte
- `realm_access.roles` enthält `admin` — für die Admin-Endpunkte

Weil die Signatur geprüft wird, sind das Aussagen von Keycloak und nicht des Aufrufers.
Ein **nicht erreichbares Keycloak beantwortet die API mit 503, nicht mit 401** — ein
Schlüsselproblem ist kein ungültiges Token. Details: [KEYCLOAK.md](KEYCLOAK.md).

Es gibt bewusst kein JWT-Shared-Secret. Tokens sind RS256; eine Anwendung, die zusätzlich
HS256 akzeptiert, lässt sich mit dem Schlüssel angreifen, den sie selbst veröffentlicht.

## Beispiele

Token holen (Demo-Benutzer aus dem Realm-Export):

```bash
TOKEN=$(curl -s -X POST http://localhost:8090/realms/betting-game/protocol/openid-connect/token \
  -d client_id=betting-game-frontend -d grant_type=password \
  -d username=admin -d password=admin123 | jq -r .access_token)
```

Tippjahr anlegen (Command, antwortet `202`):

```bash
curl -X POST http://localhost:8080/admin/tipp-years \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"name":"Tippjahr 2026","startDate":"2026-01-01","endDate":"2026-12-31","ticketCostPerRow":1.20}'
```

```json
{
  "commandId": "8f14e45f-ceea-467a-9575-6a1d3a6bd0e1",
  "status": "accepted",
  "resourceId": 1,
  "timestamp": "2026-07-29T10:00:00+00:00"
}
```

Tippreihe zuordnen:

```bash
curl -X PUT http://localhost:8080/admin/participants/2/bet-row \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"betPeriodId":1,"numbers":[3,7,12,25,38,44]}'
```

Eigene Reihe lesen (mit dem Token des Teilnehmers, dessen `participant_id` 2 ist):

```bash
curl http://localhost:8080/participants/2/bet-row -H "Authorization: Bearer $TOKEN"
```

Ein Retry mit bekanntem `Idempotency-Key` liefert die gespeicherte Antwort mit ihrem
ursprünglichen Statuscode und dem Header `Idempotent-Replay: true`.

Ein vollständiger Durchlauf vom leeren Tippjahr bis zur Ausschüttung steht in
[QUICKSTART.md](QUICKSTART.md).

## Datenmodell

20 Tabellen in [database/schema.sql](database/schema.sql).

**Read Model (13, aus Events aufgebaut):** `participant`, `tipp_year`, `membership`,
`bet_period`, `bet_row`, `ticket`, `ticket_row`, `draw`, `ticket_draw_result`,
`ticket_row_match`, `fee`, `payout`, `payout_share`.

**Event Sourcing (5):** `event_store` (unveränderliches Event-Log, Source of Truth),
`event_stream` (Stream-Metadaten mit Version), `snapshot`, `projection_state`,
`event_publisher` (Outbox, vorbereitet).

**Betrieb (1):** `command_log` — Command-Historie und Idempotency-Keys.

Die Tabelle `user` stammt aus der Zeit vor Keycloak und wird von keinem Projektor mehr
beschrieben; Identitäten liegen im Realm.

## Installation

### Mit Docker (Normalfall)

```bash
docker-compose up -d
docker-compose exec php composer install
curl http://localhost:8080/health
```

| Dienst | URL | Zugang |
|---|---|---|
| API (Caddy) | http://localhost:8080 | Bearer-Token |
| PHPMyAdmin | http://localhost:8081 | root / secret |
| Keycloak | http://localhost:8090 | Admin Console `/admin`, admin / admin |
| MariaDB | localhost:3306 | root / secret, DB `betting_game` |
| Frontend (Vue-SPA) | http://localhost:3000 | Login über Keycloak, siehe [FRONTEND.md](FRONTEND.md) |

Keycloak braucht beim ersten Start 30–60 Sekunden für den Realm-Import
(`docker-compose logs -f keycloak`). Der Stack ist in [DOCKER.md](DOCKER.md) beschrieben.

### Ohne Docker

Voraussetzungen: PHP 8.3 mit `pdo_mysql`, MariaDB 11.3 oder MySQL 8.0, Composer 2.

```bash
composer install
mysql -u root -p -e "CREATE DATABASE betting_game CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p betting_game < database/schema.sql
cp .env.example .env          # config/config.php liest alles aus Umgebungsvariablen
php -S localhost:8080 -t public
```

Für Apache oder Nginx muss jeder Pfad auf `public/index.php` zeigen; im Docker-Stack
erledigt das die `docker/Caddyfile`.

## Tests und Codequalität

```bash
docker-compose exec php vendor/bin/phpunit --testdox
docker-compose exec php vendor/bin/phpstan analyse
docker-compose exec php vendor/bin/phpcs --standard=PSR12 src tests public config
```

Ohne Docker stehen dieselben Ziele als `make test`, `make phpstan`, `make cs-check`
bereit — sie setzen ein PHP im PATH voraus. `make all-tests` fasst alle drei zusammen.

| Suite | Umfang | Voraussetzung |
|---|---|---|
| `tests/Unit` | 19 Dateien, 181 Testmethoden — Domänenlogik, Value Objects, JWT, HTTP-Helfer | keine |
| `tests/Integration` | 16 Dateien, 157 Testmethoden — Repositories, Command-Flows, HTTP-Kette, Projektions-Rebuild | MariaDB |

Die Integrationstests **überspringen sich selbst**, wenn keine Datenbank erreichbar ist.
Eine grüne Suite ohne laufende Datenbank sagt deshalb nichts über die Persistenz aus.
Testdatenbank starten: `make test-db-start`, wieder entfernen: `make test-db-stop`.

- **PHPStan Level 10** auf `src`, fehlerfrei (`phpstan.neon`, `treatPhpDocTypesAsCertain: false`)
- **PSR-12**, `declare(strict_types=1);` in jeder Datei
- 153 Dateien unter `src/`, eine Klasse pro Datei

## Abhängigkeiten

**Produktion** — sieben Pakete, kein Framework:

| Paket | Zweck |
|---|---|
| `nikic/fast-route: ^1.3` | kompiliertes Routing |
| `php-di/php-di: ^7.0` | DI-Container, in Production kompiliert |
| `ramsey/uuid: ^4.7` | Command-IDs |
| `monolog/monolog: ^3.5` | PSR-3-Implementierung |
| `psr/log`, `psr/container`, `psr/simple-cache` | Interface-Pakete |

Dazu `ext-pdo` und `ext-json`. **Entwicklung:** `phpunit/phpunit: ^11.0`,
`phpstan/phpstan: ^2.1`, `squizlabs/php_codesniffer: ^3.8`.

## Altbestand im Repository

Der Kurswechsel auf die Lotterie-Domäne (`f1d0771`) hat nicht alles mitgenommen:

| Was | Stand |
|---|---|
| [betting_game_api_e2_sports.yaml](betting_game_api_e2_sports.yaml), [database/schema-e2-sports.sql](database/schema-e2-sports.sql) | Bewusst aufgehoben für Ausbaustufe E2 |
| `docker/Caddyfile.minimal`, `Caddyfile.alternative`, `php-fpm.conf.minimal` | Reste aus dem Troubleshooting; aktiv sind nur die aus `docker-compose.yml` gemounteten |

## Lizenz

MIT. Eine `LICENSE`-Datei liegt dem Repository noch nicht bei.
