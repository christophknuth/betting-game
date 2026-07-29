# AGENTS.md

Arbeitsanleitung für KI-Agenten und neue Entwickler in diesem Repository.
Werkzeugneutral — gilt für jeden Agenten. Claude-Code-Spezifisches steht in
[CLAUDE.md](CLAUDE.md).

---

## 1. Was dieses Projekt ist

Backend-API zur Verwaltung einer **Lotterie-Tippgemeinschaft für Lotto 6 aus 49**.
PHP 8.3, kein Framework, Onion Architecture mit Event Sourcing und CQRS.

**Fachliche Kernidee** (ausführlich in [USER_STORIES.md](USER_STORIES.md)):

- Ein **Tippjahr** (`TippYear`) ist ein frei definierter Zeitraum, kein Kalenderjahr.
- Es zerfällt in überlappungsfreie **Tippperioden** (`BetPeriod`). Deren Länge ist
  Konfiguration, keine Annahme im Code — eine Periode über das ganze Jahr ist zulässig.
- Jeder Teilnehmer hat **pro Periode genau eine Tippreihe** (`BetRow`) aus sechs Zahlen.
- Monatlich wird ein gemeinsamer **Tippschein** (`Ticket`) eingereicht: ein Snapshot aller
  gültigen Reihen. Er erzeugt je Teilnehmer eine **Gebühr** (`Fee`).
- **Ziehungen** (`Draw`) erzeugen Gewinne für den Schein als Ganzes; sie werden über das
  Jahr gesammelt und am Jahresende **gleichmäßig auf alle Teilnehmer** ausgeschüttet.

**Ausbaustufe: Basis.** Teilnehmer lesen nur, der Administrator schreibt alles.
E1 (Selbstverwaltung) und E2 (Sportwetten) sind spezifiziert, aber nicht implementiert.

### Rollen

| Rolle | Keycloak-Rolle | Zugriff |
|---|---|---|
| Teilnehmer | `user` | Ausschließlich eigene Daten, nur lesend |
| Administrator / Betreiber | `admin` | Alle Schreiboperationen, Betriebssicht |

---

## 2. Welche Dokumente aktuell sind

Das Projekt wurde mit Commit `f1d0771` („Refocus the project on the Lotto 6aus49 syndicate
domain") von einem Sportwetten-Tippspiel auf die Lotterie umgestellt. Die Dokumentation ist
am 2026-07-29 nachgezogen worden.

| Dokument | Stand |
|---|---|
| [USER_STORIES.md](USER_STORIES.md) | ✅ **Aktuell und maßgeblich.** Fachliche Referenz inkl. Status je Story |
| [betting_game_api.yaml](betting_game_api.yaml) | ✅ **Aktuell** (v2.2.0, „Lotterie-Tippgemeinschaft API"). Maßgeblicher API-Vertrag |
| [betting_game_er_extended.mermaid](betting_game_er_extended.mermaid) | ✅ Aktuell |
| [database/schema.sql](database/schema.sql) | ✅ Aktuell |
| [README.md](README.md) | ✅ Aktuell. Überblick, Endpunkte, Installation |
| [ARCHITECTURE.md](ARCHITECTURE.md) | ✅ Aktuell. Schichten, Klassenlandkarte, offene Punkte |
| [QUICKSTART.md](QUICKSTART.md) | ✅ Aktuell. Ein Tippjahr von Hand durchgespielt |
| [KEYCLOAK.md](KEYCLOAK.md) | ✅ Aktuell |
| [PSR.md](PSR.md) | ✅ Aktuell. Beachte den Lesehinweis: implementiert ≠ genutzt |
| [DOCKER.md](DOCKER.md) | ✅ Aktuell, domänenneutral. Englisch, im Gegensatz zur übrigen Doku |
| [CHANGELOG.md](CHANGELOG.md) | ✅ Aktuell bis `de9215b` |
| [FRONTEND.md](FRONTEND.md), [frontend/](frontend/) | ❌ **Altbestand.** Vue-SPA bedient noch Predictions/Scores/Games — passt zu keinem Backend-Endpunkt mehr. Das Dokument beschreibt sie bewusst als Altbestand; nichts daraus als Vorlage übernehmen |
| [betting_game_api_e2_sports.yaml](betting_game_api_e2_sports.yaml), [database/schema-e2-sports.sql](database/schema-e2-sports.sql) | 📦 Bewusst aufgehoben für Ausbaustufe E2, nicht implementiert |

`DEMO.md` beschrieb ein `demo/`-Verzeichnis, das mit dem Kurswechsel entfallen ist, und
wurde gelöscht.

**Regel:** Bei Widerspruch gewinnt der Code, danach `USER_STORIES.md` und die OpenAPI-Spec.
Wer ein Dokument anfasst, korrigiert Veraltetes mit, statt es fortzuschreiben — und zieht
Zahlen (Dateien, Tests, Routen) nach, statt sie zu übernehmen.

---

## 3. Architektur

### Schichten (Abhängigkeit immer nach innen)

```
Presentation   Controller, Router, Kernel, HTTP-Helfer
     ↓
Application    Commands + Handler, Queries + Handler, Projection-Manager
     ↓
Domain         Modelle (Aggregate), Value Objects, Events, Repository-Interfaces
     ↑
Infrastructure implementiert die Domain-Interfaces (PDO, EventStore, Auth, Cache)
```

`src/Domain/` hat **keine** Abhängigkeit nach außen — keine PDO, kein HTTP, kein PSR
außer den Sprachmitteln. Wer dort einen `use BettingGame\Infrastructure\…` schreibt, hat
die Architektur gebrochen.

### Request-Fluss

```
public/index.php          Globals → Request-Objekt, Container bauen
  └─ Kernel::handle()     src/Presentation/Http/Kernel.php  ← der ganze Ablauf ist hier
       ├─ Router          FastRoute, Routentabelle in src/Presentation/Router/Router.php
       ├─ AuthMiddleware  außer bei 'public' => true. JWT gegen JWKS des Realms geprüft
       ├─ Authorization   bei 'role' => 'admin'
       ├─ command_log     bei 'command' => true (Idempotency-Key, OPS-01/OPS-02)
       ├─ Controller      Input::* validiert, Command/Query-DTO, Handler
       └─ ErrorMapper     Domain-Exception → HTTP-Status
```

Der `Kernel` ist ohne Webserver testbar; `index.php` ist nur die Brücke zu den PHP-Globals.
Neue Querschnittslogik gehört in den Kernel, nicht in `index.php` und nicht in Controller.

### Routen-Flags (`src/Presentation/Router/Router.php`)

| Flag | Wirkung |
|---|---|
| `'public' => true` | Keine Authentifizierung. **Nur** `/health` |
| `'role' => 'admin'` | Kernel erzwingt die Admin-Rolle vor dem Controller |
| `'command' => true` | Läuft unter Command-Log und Idempotency-Key |
| (nichts) | Authentifiziert; Eigentumsprüfung macht der Controller über `Authorization::requireSelf()` |

Eine Route ist **per Default authentifiziert**. Ein vergessenes Flag macht sie nicht
versehentlich öffentlich. Pfadparameter mit `{id:\d+}` einschränken, damit eine vertippte
URL 404 gibt statt 400 aus der Tiefe des Handlers.

### Event Sourcing / CQRS — wie es hier konkret läuft

- **Schreibweg:** Handler lädt Aggregat → Domänenlogik → Aggregat zeichnet Events auf
  (`RecordsEvents`-Trait) → Repository schreibt Events **und** Projektion in **einer**
  Transaktion (`EventSourcedRepository::transactionally()`) unter Optimistic Locking.
- **Leseweg:** Query-Handler liest direkt die Projektionstabellen. Keine Events, keine Joins
  über den Event Store.
- **Zwei Wege zu denselben Tabellen:** Repositories schreiben Projektionen *synchron*
  (ein Load direkt danach muss sie sehen). Die Projektoren in
  `src/Infrastructure/Projection/` sind der *zweite* Weg — sie spielen das Event-Log nach
  (`POST /admin/projections/{name}/rebuild`).
  **Beide Wege müssen dieselben Zeilen erzeugen.**
  [ProjectionRebuildTest](tests/Integration/Application/ProjectionRebuildTest.php) spielt
  ein ganzes Tippjahr durch, baut aus dem Event Store neu auf und vergleicht alle 13
  Read-Model-Tabellen zeilenweise. Wer eine Projektion ändert, ändert beide Seiten.
- **Ehrlich zur Asynchronität:** Die API beschreibt Commands als asynchron (`202`), die
  Implementierung schreibt synchron. Bei Ankunft der `202` ist der Command bereits
  `completed`, `projectionsUpToDate` ist immer `true`.
- **IDs:** `nextId()` ist `MAX(id) + 1`. Bei Nebenläufigkeit entscheidet der Unique Key auf
  der Zieltabelle, nicht eine Prüfung im Code — der Verlierer bekommt `409` und wiederholt.

### Fehler-Mapping (`src/Presentation/Http/ErrorMapper.php`)

Handler werfen Domain-Exceptions und kennen kein HTTP. Die einzige Übersetzungsstelle:

| Exception | Status |
|---|---|
| `UnauthorizedAccessException` | 403 |
| `EntityNotFoundException` | 404 |
| `InvalidArgumentException`, `InvalidInputException` | 400 |
| `ConcurrencyException` | 409 |
| `BusinessRuleViolationException` (inkl. `DuplicateEntryException`) | 409 |
| alles andere | 500 (Meldung nur im Debug-Modus) |

Ein abgelehnter Unique Key ist eine Geschäftsregel, die Nein sagt — kein Datenbankfehler.
`EventSourcedRepository` übersetzt SQLSTATE 23000 deshalb in `DuplicateEntryException`.

---

## 4. Verzeichnisstruktur

```
src/                              153 Dateien, eine Klasse pro Datei
├── Domain/                       KERN — keine Abhängigkeiten nach außen
│   ├── Model/                    Aggregate: TippYear, BetPeriod, BetRow, Ticket,
│   │                             Draw, Fee, Participant + RecordsEvents-Trait
│   ├── ValueObject/              LottoNumbers, Superzahl, DateRange, EvenSplit,
│   │                             WinningClass, TippYearStatus, Email, DisplayName, …
│   ├── Event/                    DomainEvent + 14 konkrete Events
│   ├── Repository/               Repository-Interfaces + RecordedEvent
│   ├── Service/                  WinningsDistribution (von Handler UND Projektor benutzt)
│   └── Exception/                Exception-Hierarchie unter DomainException
├── Application/
│   ├── Command/                  9 Commands + Handler, CommandResult
│   ├── Query/                    10 Queries + Handler, QueryResult
│   └── Projection/               ProjectionManager, Projector, ProjectionStatus
├── Infrastructure/
│   ├── Auth/                     TokenVerifier, JwkSet, KeycloakKeys, AuthMiddleware
│   ├── Cache/                    FileCache, RedisCache (PSR-16)
│   ├── Config/                   Config (typisierter Zugriff auf das Config-Array)
│   ├── DI/                       Container (PHP-DI), PsrContainer (PSR-11)
│   ├── EventStore/               PdoEventStore
│   ├── Persistence/              Db + Repositories, EventSourcedRepository als Basis
│   ├── Projection/               7 Projektoren, einer je Read Model
│   └── Logging/                  LoggerFactory (Monolog, PSR-3)
├── Presentation/
│   ├── Controller/               9 Controller
│   ├── Http/                     Kernel, Request, JsonResponse, Input, Authorization,
│   │                             ErrorMapper
│   └── Router/                   Router (FastRoute)
└── Support/                      Row (typisierter Zugriff auf DB-Zeilen)

tests/Unit/                       Ohne Datenbank
tests/Integration/                Braucht MariaDB, überspringt sich sonst selbst
config/config.php                 Alle Werte aus Umgebungsvariablen
database/schema.sql               20 Tabellen (13 Read Model + Event Sourcing + Ops)
docker/                           Dockerfile.php (FPM), Dockerfile.test (CLI+pcov), Caddyfile
keycloak/realm-export.json        Realm, Demo-User, Rollen, participant_id-Claim
```

---

## 5. Befehle

**Auf diesem Rechner ist PHP nicht im PATH.** Alles läuft über Docker.
Die `composer`- und `make`-Ziele darunter setzen ein lokales PHP voraus und funktionieren
nur im Container oder auf einer Maschine mit PHP 8.3.

### Über Docker (der Normalfall hier)

```bash
docker-compose up -d                              # kompletter Stack
docker-compose exec php composer install
docker-compose exec php vendor/bin/phpunit --testdox
docker-compose exec php vendor/bin/phpstan analyse
docker-compose exec php vendor/bin/phpcs --standard=PSR12 src tests public config
```

Für Tests ohne den vollen Stack existiert `docker/Dockerfile.test`
(PHP 8.3 CLI + `pdo_mysql` + `pcov`). Sie ist in kein Compose-File eingebunden und wird
direkt gebaut und gestartet.

### Makefile / Composer (mit lokalem PHP)

| Befehl | Wirkung |
|---|---|
| `make test` | Alle Tests; Integrationstests überspringen sich ohne DB |
| `make test-unit` | Nur `tests/Unit` |
| `make test-integration` | Nur `tests/Integration` (braucht `make test-db-start`) |
| `make test-db-start` / `test-db-stop` | MariaDB 11.3 auf Port 3306 mit `betting_game_test` + Schema |
| `make coverage` | HTML-Report nach `coverage/` |
| `make phpstan` | Statische Analyse, **Level 10**, Ziel `src` |
| `make cs-check` / `cs-fix` | PSR-12 prüfen / korrigieren |
| `make all-tests` (= `quality`) | PHPStan + CS + Tests |
| `make start` / `stop` / `logs` | Docker-Stack |

### Dienste im Stack

| Dienst | URL | Zugang |
|---|---|---|
| API (Caddy) | http://localhost:8080 | |
| Frontend (Altbestand, abschaltbar) | http://localhost:3000 | |
| PHPMyAdmin | http://localhost:8081 | root / secret |
| Keycloak | http://localhost:8090 | admin / admin |
| MariaDB | localhost:3306 | root / secret, DB `betting_game` |

---

## 6. Konventionen

### Code

- `declare(strict_types=1);` in **jeder** Datei unter `src/` und `tests/`.
- **Eine Klasse pro Datei**, Dateiname = Klassenname, PSR-4 1:1 zur Namespace-Struktur.
- `final` als Standard. Vererbung nur begründet (`EventSourcedRepository` ist eine
  der wenigen `abstract`-Basen).
- Value Objects sind **immutable** und validieren im Konstruktor.
- Constructor Property Promotion, `match`, Enums-artige VOs — PHP-8.3-Idiome nutzen.
- Kein `$_ENV` direkt lesen: `config/config.php` geht über `getenv()`, weil `$_ENV` in den
  offiziellen PHP-Images nicht befüllt ist. Auch keine Ausgabe vor der Response — eine
  PHP-Warning sendet Header und macht jeden Statuscode zu 200.
- Namespace-Wurzel bleibt `BettingGame\` (historisch, trotz Lotto-Domäne).

### PHPStan Level 10

`phpstan.neon` steht auf Level 10 mit `treatPhpDocTypesAsCertain: false`. Der Code ist
fehlerfrei — **halte ihn so**. Praktisch heißt das: `array<string, mixed>` aus externen
Quellen wird explizit geprüft (`is_int`, `is_string`, `assert(… instanceof …)`), nie
blind gecastet. Dafür gibt es `Support\Row` (DB-Zeilen) und `Http\Input` (Request-Bodies).
Kein `@phpstan-ignore` ohne Not.

### Kommentarstil

Die Kommentare in diesem Repo erklären **warum**, nicht was. Beispiele aus dem Bestand:

> „Der Key wird beansprucht, *bevor* der Command läuft. Erst prüfen und dann ausführen
> ließe ein Fenster, in dem zwei parallele Wiederholungen beide durchkommen — genau die
> Doppelbuchung, gegen die der Schlüssel existiert."

> „Eine Route ist authentifiziert, sofern sie nichts anderes sagt. Andersherum würde ein
> vergessenes Flag sie still öffentlich machen."

Neue Kommentare in dieser Form schreiben. Klassen-Docblocks nennen die Story-ID (`B-12`,
`OPS-02`), zu der die Klasse gehört. Keine Kommentare, die die Signatur nacherzählen.

### Sprache

- **Code, Kommentare, Docblocks, Commit-Messages: Englisch.**
- **Projektdokumentation (`USER_STORIES.md`, `ARCHITECTURE.md`, `CHANGELOG.md`): Deutsch.**
- Commit-Messages im Imperativ, eine Zeile, ohne Präfix-Tags:
  `Verify the token signature`, `Wire the base version up over HTTP`.

---

## 7. Tests

| Suite | Umfang | Voraussetzung |
|---|---|---|
| `tests/Unit` | 19 Dateien — Domänenlogik, Value Objects, Auth/JWT, HTTP-Helfer | keine |
| `tests/Integration` | 16 Dateien — Repositories, Command-Flows, HTTP-Kette, Projektions-Rebuild | MariaDB |

Insgesamt ~338 Testmethoden.

- Integrationstests **überspringen sich selbst**, wenn keine Datenbank erreichbar ist
  (`IntegrationTestCase::setUpBeforeClass()`). Die Suite bleibt damit auch ohne DB grün —
  *„alle Tests grün" ohne laufende DB heißt also nicht, dass die Persistenz geprüft wurde.*
- Repositories werden **nicht** gegen eine gemockte PDO getestet. Sie sind fast vollständig
  SQL (Unique Keys, Joins, Upserts); ein Mock würde nur bestätigen, dass wir die Strings
  geschrieben haben, die wir geschrieben haben.
- `HttpTestCase` / `ApplicationTestCase` fahren die volle Kette Kernel → Controller →
  Handler → Repository gegen die echte Datenbank.
- `tests/Support/SigningKey.php` erzeugt Token für die Auth-Tests — keine echte Keycloak nötig.

---

## 8. Eine neue Funktion hinzufügen

### Neuer Command (Schreiboperation)

1. **Domäne zuerst.** Regel im Aggregat unter `src/Domain/Model/` durchsetzen, dort ein
   Event über `recordEvent()` aufzeichnen. Neues Event nach `src/Domain/Event/`.
2. **Command + Handler** in `src/Application/Command/`. Der Handler lädt über
   Repository-Interfaces, ruft Domänenmethoden, gibt `CommandResult::accepted()` zurück.
   Er wirft Domain-Exceptions, nie HTTP.
3. **Persistenz:** Repository (erbt `EventSourcedRepository`) schreibt Events und Projektion
   in `transactionally()`. Bei Bedarf Interface in `src/Domain/Repository/` ergänzen.
4. **Projektor** in `src/Infrastructure/Projection/` nachziehen, damit ein Rebuild dieselben
   Zeilen erzeugt. Neuen Projektor in die Liste in `Container.php` (`ProjectionManager`)
   eintragen.
5. **Route** in `Router.php` mit `'command' => true` und ggf. `'role' => 'admin'`.
6. **Controller-Methode**: Body über `Input::*` lesen, `JsonResponse::accepted(...)`
   zurückgeben. Identitäten (wer gebucht hat, wessen Daten) **aus dem Token**, nie aus
   Pfad oder Body.
7. **DI:** Handler und Controller in `src/Infrastructure/DI/Container.php` registrieren
   (meist `\DI\autowire()`).
8. **Schema:** `database/schema.sql` und `betting_game_er_extended.mermaid` ergänzen.
9. **Tests:** Unit-Test für die Domänenregel, Integrationstest für den Flow, und der
   Rebuild-Test muss die neue Tabelle mit abdecken.
10. **Doku:** `betting_game_api.yaml` erweitern, Status in `USER_STORIES.md` setzen.

### Neue Query (Leseoperation)

Query-DTO + Handler in `src/Application/Query/`, Repository-Methode auf den
Projektionstabellen, Route ohne `command`-Flag, Controller-Methode mit
`Authorization::requireSelf()` bei Teilnehmerdaten, DI-Binding, Tests, OpenAPI.

---

## 9. Fallstricke

- **`Authorization::requireSelf()` nicht vergessen.** Die Identität kommt aus dem Token,
  niemals aus `{participantId}` im Pfad — sonst prüft die Regel sich selbst (B-16).
  Bewusst so streng, dass auch ein Admin hier nicht durchkommt: der Admin hat eigene
  Endpunkte, sonst wären die Teilnehmerrouten eine zweite, leisere Admin-API.
- **Kein JWT-Shared-Secret einführen.** Tokens sind RS256 von Keycloak, geprüft gegen den
  JWKS-Endpunkt. Eine Anwendung, die zusätzlich HS256 akzeptiert, lässt sich mit dem
  Schlüssel angreifen, den sie selbst veröffentlicht.
- **Unerreichbare Keycloak → 503, nicht 401.** Ein Schlüsselproblem ist kein ungültiges Token.
- **`ticket_row_match` steht in keinem Event.** Der Projektor rechnet die Zeilen über den
  Domain-Service `WinningsDistribution` neu — denselben, den der Handler benutzt. Genau
  dafür wurde er herausgezogen; Logik nicht duplizieren.
- **Rebuild zieht nach unten durch.** Read Models hängen über
  `ON DELETE CASCADE` zusammen (`participant` leeren leert `membership`, `bet_row`, `fee`).
  Ein Rebuild baut abhängige Projektionen mit auf.
- **Ein Neuaufbau ist kein Command.** `POST /admin/projections/{name}/rebuild` ist bewusst
  *nicht* mit `'command' => true` markiert — er ändert keinen Domänenzustand und gehört
  nicht in die Command-Historie.
- **Der Lebenszyklus des Tippjahres hat keine Route.** `TippYear::start()` und `close()`
  sind im Aggregat durchgesetzt, aber weder Command noch Endpunkt — sie werden nur aus
  Tests aufgerufen. Ein über HTTP angelegtes Tippjahr steht auf `planned` und nimmt in
  diesem Zustand keinen Tippschein an. Dasselbe gilt für das Anlegen eines `Participant`
  (Selbstregistrierung ist E1-01). Wer sich wundert, warum ein Durchstich bei B-12
  scheitert: das ist der Grund, nicht ein Fehler im Handler.
- **Nicht anfassen:** `vendor/`, `coverage/`, `.phpunit.cache/`, `var/` — generiert.
- **Doppelte Konfigurationsdateien** in `docker/` (`Caddyfile.minimal`,
  `Caddyfile.alternative`, `php-fpm.conf.minimal`) sind Reste aus dem Troubleshooting;
  aktiv sind nur die aus `docker-compose.yml` gemounteten.

---

## 10. Betrieb

| Story | Endpunkt |
|---|---|
| OPS-01 Verarbeitungsstand eines Commands | `GET /commands/{commandId}` |
| OPS-02 Wiederholbarkeit | Header `Idempotency-Key` auf allen Command-Routen |
| OPS-03 Event-Historie eines Aggregats | `GET /admin/audit/{type}/{id}` |
| OPS-04 Projektionen überwachen / neu aufbauen | `GET /admin/projections`, `POST /admin/projections/{name}/rebuild` |

Ein Retry mit bekanntem `Idempotency-Key` liefert die gespeicherte Antwort mit ihrem
ursprünglichen Statuscode und dem Header `Idempotent-Replay: true`.
`GET /commands/{commandId}` ist nicht admin-geschützt: wer den Command abgesetzt hat, darf
nachsehen, und die UUID kann niemand raten.
