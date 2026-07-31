# Architektur

Wie die Anwendung aufgebaut ist und warum. Die Fachlichkeit steht in
[USER_STORIES.md](USER_STORIES.md), die Arbeitsanleitung in [AGENTS.md](AGENTS.md).

Stand: Ausbaustufe Basis, vollständig implementiert — 18 fachliche Stories, 4 Betriebsstories,
23 Routen.

---

## 1. Onion Architecture

```
┌──────────────────────────────────────────────┐
│ Presentation   Controller, Router, Kernel    │ → hängt an Application
├──────────────────────────────────────────────┤
│ Application    Commands, Queries, Projection │ → hängt an Domain
├──────────────────────────────────────────────┤
│ Domain         Aggregate, VOs, Events        │ → hängt an nichts
├──────────────────────────────────────────────┤
│ Infrastructure PDO, EventStore, Auth, Cache  │ → implementiert Domain-Interfaces
└──────────────────────────────────────────────┘
```

`src/Domain/` importiert nichts außerhalb von `BettingGame\Domain` — kein PDO, kein HTTP,
keine PSR-Pakete. Wer dort ein `use BettingGame\Infrastructure\…` schreibt, hat die
Architektur gebrochen. Der praktische Nutzen ist nicht Austauschbarkeit im Prospekt, sondern
dass die Domänenregeln ohne Datenbank testbar sind: `tests/Unit/Domain/` läuft ohne alles.

Die einzige Stelle, die alle Schichten kennt, ist
[Container.php](src/Infrastructure/DI/Container.php).

---

## 2. Request-Fluss

```
public/index.php          Globals → Request, Container bauen, Response senden
  └─ Kernel::handle()     src/Presentation/Http/Kernel.php
       ├─ Router          FastRoute, Routentabelle in Presentation/Router/Router.php
       ├─ AuthMiddleware  außer bei 'public' => true
       ├─ Authorization   bei 'role' => 'admin'
       ├─ command_log     bei 'command' => true
       ├─ Controller      Input::* validiert, Command/Query-DTO, Handler
       └─ ErrorMapper     Domain-Exception → HTTP-Status
```

Der ganze Ablauf steht im [Kernel](src/Presentation/Http/Kernel.php), nicht in
`index.php` und nicht in den Controllern. Das ist der Grund, warum
[HttpTestCase](tests/Integration/Http/HttpTestCase.php) die komplette Kette ohne Webserver
fahren kann. Neue Querschnittslogik gehört genau dorthin.

### Routen-Flags

| Flag | Wirkung |
|---|---|
| `'public' => true` | Keine Authentifizierung. **Nur** `/health` |
| `'role' => 'admin'` | Kernel erzwingt die Admin-Rolle vor dem Controller |
| `'command' => true` | Läuft unter Command-Log und Idempotency-Key |
| (nichts) | Authentifiziert; die Eigentumsprüfung macht der Controller |

Eine Route ist **per Default authentifiziert**. Andersherum würde ein vergessenes Flag sie
still öffentlich machen. Pfadparameter sind mit `{id:\d+}` eingeschränkt, damit eine
vertippte URL `404` gibt statt `400` aus der Tiefe eines Handlers.

### Zugriffsschutz

`Authorization::requireSelf()` vergleicht die `participantId` aus dem Pfad mit dem
`participant_id`-Claim des Tokens. Die Identität kommt **aus dem Token**, sonst bestätigte
die Prüfung nur sich selbst. Sie ist bewusst so streng, dass auch ein Admin nicht
durchkommt — der hat eigene Endpunkte, sonst wären die Teilnehmerrouten eine zweite,
leisere Admin-API.

Die Prüfung läuft **vor** der Query. Andernfalls verriete ein `404` bereits, dass zu einem
fremden Teilnehmer nichts existiert.

---

## 3. Event Sourcing und CQRS

### Schreibweg

```
Command-DTO
  → Handler                lädt Aggregat über Repository-Interface
    → Domänenmethode       setzt die Regel durch, recordEvent()
      → Repository         transactionally(): Events + Projektion, ein COMMIT
        → CommandResult    202 accepted
```

Aggregate zeichnen ihre Events über das Trait
[RecordsEvents](src/Domain/Model/RecordsEvents.php) auf. Das Speichern läuft über
[EventSourcedRepository](src/Infrastructure/Persistence/EventSourcedRepository.php), die
gemeinsame `abstract`-Basis aller Repositories — eine der wenigen Vererbungen im Projekt.

### Leseweg

Query-Handler lesen direkt die Projektionstabellen. Keine Events, keine Rekonstruktion,
keine Joins über den Event Store. Deshalb bleibt eine Leseabfrage ein einfaches `SELECT`,
auch wenn das Aggregat dahinter 200 Events hat.

### Die zwei Wege zu denselben Zeilen

Repositories schreiben ihre Projektion **synchron** beim Speichern — ein `load()` direkt
danach muss die Zeile sehen. Die sieben Projektoren in `src/Infrastructure/Projection/`
sind der *zweite* Weg: sie spielen das Event-Log nach, angestoßen über
`POST /admin/projections/{name}/rebuild`.

Zwei Wege zu denselben Tabellen driften auseinander, wenn niemand nachsieht. Deshalb spielt
[ProjectionRebuildTest](tests/Integration/Application/ProjectionRebuildTest.php) ein ganzes
Tippjahr durch die Command-Handler, fotografiert alle 13 Read-Model-Tabellen, baut aus dem
Event Store neu auf und vergleicht zeilenweise. Einzige zugelassene Abweichung:
`ticket_row_match.calculated_at` — das hält fest, *wann* gerechnet wurde.

**Wer eine Projektion ändert, ändert beide Seiten.**

### Optimistic Locking

Der Event Store schreibt mit erwarteter Streamversion. Stimmt sie nicht, wirft er
`ConcurrencyException` → `409`. Der Verlierer wiederholt; dank Idempotency-Key ist das
gefahrlos.

IDs vergibt `nextId()` als `MAX(id) + 1`. Bei Nebenläufigkeit entscheidet der Unique Key
auf der Zieltabelle, nicht eine Prüfung im Code.

### Ehrlich zur Asynchronität

Die OpenAPI-Spezifikation beschreibt Commands als asynchron (`202 accepted`). Diese
Implementierung schreibt synchron: wenn der Aufrufer die `202` hat, ist der Command bereits
`completed` und `projectionsUpToDate` immer `true`. Der Statusendpunkt bleibt trotzdem
sinnvoll — dort schlägt ein Retry nach, was der erste Versuch erzeugt hat.

---

## 4. Klassenlandkarte

155 Dateien unter `src/`, eine Klasse pro Datei, PSR-4 1:1 zur Namespace-Struktur.
Namespace-Wurzel ist historisch `BettingGame\`, trotz Lotto-Domäne.

### Domain (`src/Domain/`)

| Verzeichnis | Inhalt |
|---|---|
| `Model/` | 7 Aggregate — `TippYear`, `BetPeriod`, `BetRow`, `Ticket`, `Draw`, `Fee`, `Participant` — plus das Trait `RecordsEvents` |
| `ValueObject/` | `LottoNumbers`, `Superzahl`, `DateRange`, `EvenSplit`, `WinningClass`, `TippYearStatus`, `ParticipantId`, `Email`, `DisplayName` |
| `Event/` | `DomainEvent` + 14 konkrete Events |
| `Repository/` | 10 Interfaces + `RecordedEvent` |
| `Service/` | `WinningsDistribution` |
| `Exception/` | 7 Klassen unter `DomainException` |

**Value Objects sind immutable und validieren im Konstruktor.** `LottoNumbers` nimmt genau
sechs verschiedene Zahlen aus 1–49 und speichert sie aufsteigend; `Superzahl` 0–9. Ein
Aggregat kann damit gar nicht erst in einen ungültigen Zustand geraten.

`EvenSplit` teilt Geldbeträge **in ganzen Cent** und legt den Rest auf den ersten Anteil.
In Fließkomma zu teilen und je Anteil zu runden erzeugt oder vernichtet Geld: 100,00 € auf
drei ergibt dreimal 33,33 € und ein Cent verschwindet. Betrifft die Jahresausschüttung
(B-13) und die Verteilung eines Scheingewinns auf die Reihen (B-09).

`WinningsDistribution` liegt im **Domain-Service**, weil zwei Aufrufer dieselbe Rechnung
brauchen: der Command-Handler beim Eintragen der Gewinne und der `DrawProjector` beim
Neuaufbau. `ticket_row_match` steht in keinem Event — der Projektor muss die Zeilen neu
rechnen, und zwar mit derselben Logik.

### Exception-Hierarchie

```
DomainException
├── InvalidArgumentException          → 400
├── EntityNotFoundException           → 404
├── ConcurrencyException              → 409
├── BusinessRuleViolationException    → 409
│   └── DuplicateEntryException       → 409
└── UnauthorizedAccessException       → 403
```

`DuplicateEntryException` gibt es, weil Regeln wie „eine Reihe pro Teilnehmer und Periode"
im Schema stehen, nicht im Code. Ohne sie müsste die Application-Schicht `PDOException`
fangen und SQLSTATE lesen, um zu erkennen, dass eine *Fachregel* abgelehnt hat.

### Application (`src/Application/`)

| Verzeichnis | Inhalt |
|---|---|
| `Command/` | 9 Commands + Handler, `CommandResult` |
| `Query/` | 10 Queries + Handler, `QueryResult` |
| `Projection/` | `ProjectionManager`, `Projector`, `ProjectionStatus` |

Handler kennen kein HTTP: sie nehmen ein DTO, arbeiten über Repository-Interfaces und
werfen Domänen-Ausnahmen.

| Story | Command-Handler | Query-Handler |
|---|---|---|
| B-01 … B-04 | — | `GetBetRow`, `GetMemberships`, `GetParticipantFees`, `GetPayoutShare` |
| B-05 | — | `GetDraws` |
| B-06 | `AssignBetRow` | — |
| B-07 | `RecordFeePayment` | `GetFees` |
| B-08 / B-09 | `RecordDraw`, `RecordDrawWinnings` | — |
| B-10 / B-14 | `CreateTippYear`, `CreateBetPeriod` | `GetTippYears`, `GetBetPeriods` |
| B-11 … B-13 | `AddMember`, `SubmitTicket`, `DistributePayout` | — |
| OPS-01 / OPS-03 | — | `GetCommandStatus`, `GetAuditTrail` |

### Infrastructure (`src/Infrastructure/`)

| Verzeichnis | Inhalt |
|---|---|
| `Auth/` | `TokenVerifier`, `JwkSet`, `KeycloakKeys`, `StaticKeys`, `KeycloakService`, `AuthMiddleware`, `CurlFetcher` |
| `Cache/` | `FileCache`, `RedisCache` (PSR-16) |
| `Config/` | `Config` — typisierter Zugriff auf das Config-Array |
| `DI/` | `Container` (PHP-DI), `PsrContainer` (PSR-11) |
| `EventStore/` | `PdoEventStore` |
| `Persistence/` | `Db` + 9 Repositories, `EventSourcedRepository` als Basis |
| `Projection/` | 7 Projektoren, einer je Read Model |
| `Logging/` | `LoggerFactory` (Monolog, PSR-3) |

Welches Aggregat welche Projektionen schreibt:

| Aggregat | Repository | Projektionen |
|---|---|---|
| `TippYear` | `TippYearRepository` | `tipp_year`, `membership`, `payout`, `payout_share` |
| `BetPeriod` | `BetPeriodRepository` | `bet_period` |
| `BetRow` | `BetRowRepository` | `bet_row` |
| `Ticket` | `TicketRepository` | `ticket`, `ticket_row` |
| `Draw` | `DrawRepository` | `draw`, `ticket_draw_result`, `ticket_row_match` |
| `Fee` | `FeeRepository` | `fee` |
| `Participant` | `ParticipantRepository` | `participant` |

**Zwei Entscheidungen, die man beim Lesen sonst übersieht:**

Ein neues Aggregat wird mit einem reinen `INSERT` geschrieben, ein geladenes mit `UPDATE` —
kein `ON DUPLICATE KEY UPDATE`. Das trifft *jeden* Unique Key und würde bei einer zweiten
Tippreihe für dieselbe Periode die vorhandene stillschweigend überschreiben, statt den
`409` auszulösen, den B-06 verlangt.

Append und Projektionsschreiben laufen in **einer** Transaktion. Sonst bliebe nach einer
vom Unique Key abgelehnten Reihe ein `bet_row.assigned`-Event im Store stehen, das keine
Zeile beschreibt.

### Presentation (`src/Presentation/`)

| Verzeichnis | Inhalt |
|---|---|
| `Controller/` | 9 Controller |
| `Http/` | `Kernel`, `Request`, `JsonResponse`, `Input`, `Authorization`, `ErrorMapper`, `InvalidInputException` |
| `Router/` | `Router` (FastRoute) |

### Support (`src/Support/`)

`Row` — typisierter Zugriff auf eine Datenbankzeile. Zusammen mit `Http\Input` (für
Request-Bodies) ist das der Grund, warum PHPStan Level 10 ohne Casts durchgeht: `mixed` aus
externen Quellen wird an genau zwei Stellen geprüft statt überall geraten.

---

## 5. Betrieb

| Story | Umsetzung |
|---|---|
| OPS-01 | `GET /commands/{commandId}` — Verarbeitungsstand aus `command_log` |
| OPS-02 | Header `Idempotency-Key` auf allen Command-Routen |
| OPS-03 | `GET /admin/audit/{type}/{id}` — Event-Historie eines Aggregats |
| OPS-04 | `GET /admin/projections`, `POST /admin/projections/{name}/rebuild` |

**Der Idempotency-Key wird beansprucht, *bevor* der Command läuft.** Erst prüfen und dann
ausführen ließe ein Fenster, in dem zwei parallele Wiederholungen beide durchkommen — genau
die Doppelbuchung, gegen die der Schlüssel existiert. Der Unique Key auf der Spalte
entscheidet das Rennen. Ein Retry liefert die gespeicherte Antwort mit ihrem ursprünglichen
Statuscode und dem Header `Idempotent-Replay: true`.

Die `commandId` der Antwort ist der Primärschlüssel im `command_log`: der Handler erzeugt
eine eigene, der Kernel überschreibt sie mit der protokollierten, damit
`GET /commands/{id}` sie auch findet.

**Ein Neuaufbau ist kein Command.** `POST /admin/projections/{name}/rebuild` ist bewusst
*nicht* mit `'command' => true` markiert — er ändert keinen Domänenzustand und gehört nicht
in die Command-Historie.

**Ein Neuaufbau zieht nach unten durch.** Die Read Models hängen über
`ON DELETE CASCADE` zusammen: `participant` zu leeren leert auch `membership`, `bet_row`
und `fee`. Ein Rebuild baut die abhängigen Projektionen deshalb mit auf — sonst blieben sie
leer und niemand merkte es. Die Antwort listet alle tatsächlich neu aufgebauten.

---

## 6. Authentifizierung

Die Identität kommt aus dem Token — also hängt jede Regel oben daran, dass das Token
wirklich von Keycloak stammt. Geprüft wird in dieser Reihenfolge:

| Prüfung | Wogegen |
|---|---|
| `alg` gegen eine Allowlist | `alg: none`; HS256 mit dem öffentlichen Schlüssel als „Secret" |
| Signatur gegen den Public Key aus dem JWKS | gefälschte und nachträglich geänderte Tokens |
| `exp`, `nbf`, `iat` (mit Leeway) | abgelaufene Tokens, Uhrendrift |
| `iss` exakt | ein gültig signiertes Token des falschen Realms |
| `aud`, wenn konfiguriert | ein Token für einen anderen Client |

Die Allowlist **kann nur asymmetrische Verfahren enthalten** — der Konstruktor lehnt alles
andere ab. Beide klassischen Fälschungen scheitern damit an derselben Stelle, und ein
`HS256` in der Konfiguration fällt beim Start auf statt auf dem Request, der damit
gefälscht worden wäre.

Der Schlüssel kommt **immer aus dem Key Set, nie aus dem Token**. Eine unbekannte `kid`
löst genau einen gedrosselten Refetch aus. Nicht erreichbares Keycloak ist **503, nicht
401**. Ein zuletzt bekanntes Key Set überlebt einen Ausfall — Signaturschlüssel rotieren im
Monatsrhythmus, Tokens laufen binnen einer Stunde ab.

Details und Konfiguration: [KEYCLOAK.md](KEYCLOAK.md).

---

## 7. Tests

| Suite | Dateien | Testmethoden | Voraussetzung |
|---|---|---|---|
| `tests/Unit` | 19 | 181 | keine |
| `tests/Integration` | 16 | 157 | MariaDB |

- Integrationstests **überspringen sich selbst**, wenn keine Datenbank erreichbar ist
  (`IntegrationTestCase::setUpBeforeClass()`). „Alle Tests grün" ohne laufende Datenbank
  heißt deshalb nicht, dass die Persistenz geprüft wurde.
- Repositories werden **nicht** gegen eine gemockte PDO getestet. Sie sind fast vollständig
  SQL — Unique Keys, Joins, Upserts; ein Mock würde nur bestätigen, dass wir die Strings
  geschrieben haben, die wir geschrieben haben.
- Auch Handler laufen mit **echten** Repositories: welche Zeilen eine Query liefert, welcher
  Unique Key greift und ob eine Projektion konsistent endet, kann nur eine Datenbank
  beantworten.
- `HttpTestCase` / `ApplicationTestCase` fahren die volle Kette Kernel → Controller →
  Handler → Repository.
- `tests/Support/SigningKey.php` erzeugt Tokens für die Auth-Tests — keine echte Keycloak
  nötig.

```bash
make test-db-start && make test-integration && make test-db-stop
```

---

## 8. Codequalität

- **PHPStan Level 10** auf `src`, `treatPhpDocTypesAsCertain: false`, fehlerfrei.
  `array<string, mixed>` aus externen Quellen wird explizit geprüft (`is_int`, `is_string`,
  `assert(… instanceof …)`), nie blind gecastet. Kein `@phpstan-ignore` ohne Not.
- **PSR-12**, geprüft über `phpcs` auf `src tests public config`.
- `declare(strict_types=1);` in jeder Datei unter `src/` und `tests/`.
- `final` als Standard; `EventSourcedRepository` ist eine der wenigen `abstract`-Basen.
- Kommentare erklären **warum**, nicht was. Klassen-Docblocks nennen die Story-ID.

---

## 9. Offene Punkte

**Fachlich**

- **Die HTTP-Oberfläche der Basis ist vollständig.** Der Lebenszyklus des Tippjahres läuft
  seit B-18 über `PUT /admin/tipp-years/{id}/status`, Teilnehmer entstehen seit B-21 über
  `POST /admin/participants`. Ein Durchstich braucht damit kein `INSERT` von Hand mehr.
  **Selbst**registrierung bleibt E1-01.
- E1 (Selbstverwaltung) und E2 (Sportwetten) sind spezifiziert, aber nicht implementiert.
  Die E2-Artefakte liegen als [betting_game_api_e2_sports.yaml](betting_game_api_e2_sports.yaml)
  und [database/schema-e2-sports.sql](database/schema-e2-sports.sql) bereit.
- Das [frontend/](frontend/) bedient die Basisversion vollständig und hat automatisierte
  Tests: Vitest für Composables, Stores und den Router-Guard, Playwright für den Durchstich
  gegen den laufenden Stack — siehe [FRONTEND.md](FRONTEND.md).

**Technisch**

- **Cache:** PSR-16 ist implementiert und wird produktiv nur von `KeycloakKeys` genutzt
  (JWKS-Cache). Read Models werden nicht gecacht.
- **Logging:** PSR-3 ist verdrahtet, aber nur `AuthMiddleware` schreibt. Command- und
  Query-Handler loggen nicht.
- **`event_publisher`** existiert als Outbox-Tabelle; es gibt keinen Publisher, der sie
  leert. Ereignisse verlassen das System nicht.
- **`snapshot`** existiert; es wird kein Snapshot geschrieben oder gelesen. Bei den
  aktuellen Streamlängen ist das kein Problem.
- Die Tabelle `user` stammt aus der Zeit vor Keycloak und wird von keinem Projektor mehr
  beschrieben. Über B-21 angelegte Teilnehmer lassen `participant.user_id` deshalb `NULL`
  — die Spalte war im Schema von jeher nullable („guest participants have no account"),
  nur das Aggregat verlangte bis dahin einen Wert.
- Kein Rate Limiting, keine Metriken, kein Tracing.

**Bewusst nicht getan**

- Kein Framework. Die Kosten wären Autoload- und Bootstrap-Overhead für Bausteine, die hier
  aus je einer Klasse bestehen.
- Keine PSR-7/PSR-15-Umstellung. `Request`/`JsonResponse` sind klein und tun, was sie
  sollen; sinnvoll wäre der Wechsel nur zusammen, siehe [PSR.md](PSR.md).
- Keine gemessenen Performance-Zahlen. Es gibt kein Benchmark-Setup im Repository, und
  geschätzte Zahlen in einer Architekturdoku sind schlimmer als keine.
