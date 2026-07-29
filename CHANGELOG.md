# Changelog

Chronik der größeren Umbauten, neueste zuerst. Der aktuelle Stand steht in
[README.md](README.md) und [ARCHITECTURE.md](ARCHITECTURE.md) – dieses Dokument hält nur
fest, was wann und warum geändert wurde.

---

## Die Datenbank enthielt noch das Sportwetten-Schema (2026-07-29)

**Jede authentifizierte Query endete in einem `500`.** Nachdem Realm und `iss` in Ordnung
waren, kam der nächste Fehler derselben Familie zum Vorschein: In `betting_game` standen
`prediction`, `betting_game`, `game_participation`, `participant_score` und `result` —
und keine einzige Lotto-Tabelle. Eine Query gegen `bet_period` warf eine `PDOException`,
die nicht in der Domain-Hierarchie steht und deshalb als `500` herauskam.

`database/schema.sql` ist unter `/docker-entrypoint-initdb.d/` gemountet, und dieses
Verzeichnis wird **nur bei leerem Datenverzeichnis** ausgeführt. Das Volume `db_data`
stammte von vor dem Kurswechsel — seit `f1d0771` lief der Stack also auf dem Schema der
alten Domäne, ohne dass das irgendwo aufgefallen wäre.

Eingespielt, ohne das Volume zu löschen: `schema.sql` beginnt mit `DROP TABLE IF EXISTS`
für alle Tabellen. Die Reihenfolge dieser `DROP`s ist allerdings auf den *neuen*
Fremdschlüsselgraphen ausgelegt und scheitert an fremden Constraints — mit
`SET FOREIGN_KEY_CHECKS=0` für die Sitzung läuft sie durch.

Verifiziert:

| Aufruf | Ergebnis |
|---|---|
| `GET /health` | `200`, `"domain":"lotto-syndicate"` |
| `GET /participants/2/bet-row` (eigene Daten) | `404` „No tipp year covers 2026-07-29" |
| `GET /participants/1/bet-row` (fremde Daten) | `403` „You may only access your own data" (B-16) |
| `GET /admin/tipp-years` ohne Admin-Rolle | `403` „Admin access required" (B-17) |
| `GET /admin/tipp-years` mit Admin-Rolle | `200`, `{"tippYears":[]}` |
| `GET /admin/projections` | `200`, alle 7 Projektionen `upToDate` |

`AGENTS.md` Abschnitt 9 hält den Fallstrick fest — er gilt für `db_data` und
`keycloak_db_data` gleichermaßen: **beide Volumes überleben jede Änderung an der Datei,
aus der sie einmal befüllt wurden.**

Zurückgeblieben sind zehn verwaiste Tabellen der alten Domäne (`prediction`, `user`,
`game_type` …). Sie stören nicht, weil kein Code sie anfasst, und sind noch zu entfernen.

---

## Redirect-Schleife nach dem Login (2026-07-29)

**Nach der Anmeldung blitzte „Invalid or expired token" auf, dann ging es zur
Keycloak-Anmeldung und sofort wieder zurück — endlos.** Zwei Fehler, die sich gegenseitig
verdeckt haben.

**Der `iss`-Claim passte nicht.** Keycloak stellt das Token für einen Browser aus und
schreibt die URL hinein, unter der dieser es geholt hat:
`http://localhost:8090/realms/betting-game`. `TokenVerifier` vergleicht `iss` exakt
(`hash_equals`) und erwartete ohne `KEYCLOAK_ISSUER` den Wert aus `KEYCLOAK_URL` — also den
*internen* Hostnamen `http://keycloak:8080/realms/betting-game`. Das `php`-Service in
`docker-compose.yml` setzte **gar keine** `KEYCLOAK_*`-Variablen, obwohl `config/config.php`
im Kommentar genau auf diesen Unterschied hinweist. Jedes intakte Token war damit
ungültig. Gesetzt sind jetzt beide Adressen, für ihre je eigene Aufgabe:
`KEYCLOAK_URL` für die Erreichbarkeit des JWKS, `KEYCLOAK_ISSUER` für die Identität im
Token.

**Der Client machte daraus eine Schleife.** Der Response-Interceptor schickte bei *jedem*
`401` zur Anmeldung. Keycloak hat aber eine gültige Sitzung, liefert dasselbe Token
zurück, und der nächste Request beginnt von vorn — der eigentliche Fehler war für den
Bruchteil einer Sekunde sichtbar. Angemeldet wird jetzt nur noch, wenn gar keine Sitzung
besteht; ein `401` mit bestehender Sitzung ist ein Konfigurationsfehler und bleibt stehen.
Den Fall „Sitzung wirklich abgelaufen" behandelt jetzt der Request-Interceptor an der
Stelle, an der er ihn erkennen kann: wenn `updateToken` fehlschlägt.

Weil die API bewusst nicht sagt, *warum* sie ein Token ablehnt, nennt `errors.js` bei
einem `401` die wahrscheinlichste Ursache — auf dem Client, wo das nichts preisgibt.

---

## Realm-Export machte die Autorisierung wirkungslos (2026-07-29)

**Kein Token dieses Realms trug jemals `participant_id`, `realm_access.roles` oder
`preferred_username`.** Aufgefallen an der Meldung „Dieses Token trägt keinen
`participant_id`-Claim" in der Oberfläche — die Ursache lag tiefer und betraf das Backend
genauso.

Der Export definierte einen Top-Level-Block `clientScopes` mit dem einen Scope
`participant_id`. Keycloak liest so einen Block als *die vollständige Liste* der Client
Scopes des Realms und legt die eingebauten (`profile`, `email`, `roles`, `web-origins`,
`acr`) dann gar nicht erst an. Die `defaultClientScopes` des Frontend-Clients verwiesen
damit auf fünf Scopes, die es nicht gab — und Keycloak verwirft solche Verweise
stillschweigend. Der Client stand am Ende mit **null** zugewiesenen Scopes da.

Nachgemessen am laufenden Realm, nicht am Export:

```
GET /admin/realms/betting-game/client-scopes
  → offline_access, participant_id          (statt zusätzlich profile, email, roles, …)
GET /admin/realms/.../clients/{id}/default-client-scopes
  → []
```

**Die Auswirkung war nicht kosmetisch.** Ohne `realm_access.roles` liefert
`Authorization::requireAdmin()` für jeden `403`, ohne `participant_id` gilt dasselbe für
B-01 bis B-04. Die gesamte Rechteprüfung war wirkungslos — nicht zu lax, sondern
vollständig zu: Keine Route mit Identitäts- oder Rollenbezug war benutzbar. Ein Fehler
stand nirgends, weil aus Sicht jeder einzelnen Komponente alles korrekt war.

- Der Block `clientScopes` ist entfernt, damit Keycloak seine eingebauten Scopes anlegt.
- Der `participant_id`-Mapper hängt jetzt **direkt am Client** (`protocolMappers`). Ein
  Mapper am Client kann nicht ins Leere verweisen; ein Scope-Verweis kann es.
- `KEYCLOAK.md` beschreibt die Falle, den Prüfbefehl am laufenden Realm und den
  Neuimport.

**Die Änderung wirkt erst nach einem Neuimport.** `--import-realm` importiert nur, wenn
der Realm noch nicht existiert, und er liegt im Volume `betting-game_keycloak_db_data`.
Befehle in [KEYCLOAK.md](KEYCLOAK.md).

---

## ESLint für das Frontend (2026-07-29)

**Das Lint-Skript stand in der `package.json`, ohne dass es eine Konfiguration gab** — es
schlug immer fehl und wurde darum nie benutzt.

- [`frontend/.eslintrc.cjs`](frontend/.eslintrc.cjs) mit `eslint:recommended` +
  `plugin:vue/vue3-recommended`, der strengsten der drei Vue-Voreinstellungen. Eine
  einzige Ausnahme: `vue/multi-word-component-names` erlaubt `App`.
- `.cjs`, weil die `package.json` `"type": "module"` deklariert.
- `npm run lint` prüft jetzt nur noch, `npm run lint:fix` korrigiert. Vorher trug das
  Lint-Skript ein `--fix` — ein Prüfbefehl, der die Dateien ändert, ist in einer Pipeline
  nicht zu gebrauchen.

**Ergebnis der ersten Prüfung: 515 Verstöße, davon 0 Fehler.** Alle stammten aus vier
Formatierungsregeln (`max-attributes-per-line`, `singleline-html-element-content-newline`,
`html-self-closing`, `multiline-html-element-content-newline`) und waren automatisch
korrigierbar. Dass aus `eslint:recommended` und den Vue-Fehlerregeln nichts kam, heißt:
keine ungenutzten Variablen, keine unbekannten Bezeichner, keine fehlenden `:key`.

Die Formatierungsregeln sind bewusst **nicht** abgeschaltet worden, obwohl sie die
Templates länger machen. Sie ersetzen den Formatter, den dieses Projekt nicht hat.

**Eine Stelle war danach kaputt und ist von Hand korrigiert:** In `DrawsView` stand
„5 Richtige + Superzahl“ als zwei Markup-Fragmente, deren Abstand davon abhing, wo die
Zeile umbrach. Der Formatierer darf woanders umbrechen — die Zeichenkette wird jetzt in
JavaScript zusammengesetzt statt aus Markup.

---

## Frontend auf die Lotto-Domäne umgestellt (2026-07-29)

**Die SPA rief Endpunkte auf, die es seit dem Kurswechsel nicht mehr gab.** Predictions,
Scores und Games — jeder fachliche Request lief in einen `404`, nur Login und Logout
funktionierten. Das war seit `f1d0771` so und in [FRONTEND.md](FRONTEND.md) als Altbestand
dokumentiert, statt behoben zu sein.

- **Ersetzt:** `services/api.js` (eine Methode je Route in
  [Router.php](src/Presentation/Router/Router.php)), `router/index.js`, `App.vue` und alle
  neun Views. Die acht Prediction-/Score-/Game-Views sind gelöscht.
- **Neu:** je eine Ansicht für B-01 bis B-14 sowie OPS-01/03/04 — fünf lesende
  Teilnehmeransichten, fünf Adminansichten. Die Zuordnung Ansicht → Endpunkt steht in
  [FRONTEND.md](FRONTEND.md).
- **Geblieben:** `stores/auth.js` und `services/keycloak.js`. Die Anmeldung war das
  einzige, was vorher noch funktionierte, und sie ist domänenneutral.

**Der Idempotency-Key wird jetzt benutzt, statt nur zu existieren.** `useCommand`
behält den Schlüssel genau dann, wenn *keine* Antwort kam — dann und nur dann ist unklar,
ob der Server geschrieben hat, und eine Wiederholung mit demselben Schlüssel bekommt das
gespeicherte Ergebnis statt einer zweiten Buchung. Sobald irgendein Status zurückkommt,
ist der Schlüssel verbraucht: Ein fehlgeschlagener Schlüssel bleibt serverseitig vergeben,
und ihn nach einem `400` weiterzuverwenden würde einen behebbaren Eingabefehler dauerhaft
in ein `409` verwandeln.

**Zwei Dinge, die die Oberfläche sichtbar macht statt zu verstecken:**

- Ein Token ohne `participant_id`-Claim bekommt in den Teilnehmeransichten einen Hinweis,
  keine leere Seite. `Authorization::requireSelf()` lässt dort auch einen Administrator
  nicht durch — das ist Absicht, kein Fehler.
- Ein `404` in einer Leseansicht ist ein Leerzustand, kein Fehler: „für diese Periode ist
  keine Reihe hinterlegt“ ist eine Antwort.

Ein `503` führt bewusst **nicht** zur Anmeldung: Keycloak ist dann nicht erreichbar, und
den Benutzer dorthin zu schicken hieße, ihn zu dem Dienst zu schicken, von dem wir gerade
wissen, dass er nicht antwortet. Nur `401` wirft ihn zum Login.

Geprüft über `docker-compose build frontend` (Vite-Build, 119 Module, fehlerfrei). Die SPA
hat weiterhin **keine automatisierten Tests**, und `npm run lint` ist ohne
ESLint-Konfiguration nicht lauffähig — beides steht in [FRONTEND.md](FRONTEND.md) unter
„Offene Punkte“.

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

**Nicht mitgegangen:** [frontend/](frontend/) bediente weiterhin Predictions, Scores und
Games und passte zu keinem Endpunkt mehr. Nachgezogen am 2026-07-29, siehe den obersten
Eintrag.

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
