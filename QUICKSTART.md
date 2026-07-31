# Schnelleinstieg

Vom leeren Repository zu einem durchgespielten Tippjahr. Fachlicher Hintergrund:
[USER_STORIES.md](USER_STORIES.md), Architektur: [ARCHITECTURE.md](ARCHITECTURE.md).

## 1. Stack starten

```bash
docker-compose up -d
docker-compose exec php composer install
curl http://localhost:8080/health          # {"status":"healthy","timestamp":"..."}
```

| Dienst | URL | Zugang |
|---|---|---|
| API (Caddy) | http://localhost:8080 | Bearer-Token |
| PHPMyAdmin | http://localhost:8081 | root / secret |
| Keycloak | http://localhost:8090 | Admin Console `/admin`, admin / admin |
| MariaDB | localhost:3306 | root / secret, DB `betting_game` |

Keycloak braucht beim ersten Start 30–60 Sekunden für den Realm-Import:
`docker-compose logs -f keycloak`, warten auf `Keycloak 23.0.x started`.

Das Schema wird beim ersten Start der Datenbank automatisch aus
[database/schema.sql](database/schema.sql) geladen. Neu einspielen: `make db-reset`.

> Dieser Durchstich läuft bewusst über `curl`. Dieselben Schritte gibt es auch als
> Oberfläche im Container `frontend` auf Port 3000 ([FRONTEND.md](FRONTEND.md)); wer sie
> nicht braucht, hält ihn an: `docker-compose stop frontend`.

## 2. Token holen

Die Demo-Benutzer stehen in [keycloak/realm-export.json](keycloak/realm-export.json):

| Username | Passwort | Rollen | `participant_id` |
|---|---|---|---|
| `admin` | `admin123` | user, admin | 1 |
| `testuser` | `test123` | user | 2 |
| `john.doe` | `password` | user | 3 |

```bash
token() {
  curl -s -X POST http://localhost:8090/realms/betting-game/protocol/openid-connect/token \
    -d client_id=betting-game-frontend -d grant_type=password \
    -d "username=$1" -d "password=$2" | jq -r .access_token
}

ADMIN=$(token admin admin123)
USER=$(token testuser test123)
```

Ohne gültiges Token antwortet jede Route außer `/health` mit `401`. Ist Keycloak nicht
erreichbar, kommt **503** — ein Schlüsselproblem ist kein ungültiges Token.

## 3. Teilnehmer anlegen (B-21)

```bash
api() { curl -s -X "$1" "http://localhost:8080$2" \
  -H "Authorization: Bearer $ADMIN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" ${3:+-d "$3"}; }

api POST /admin/participants '{"displayName":"Admin"}'
api POST /admin/participants '{"displayName":"Test User"}'

curl -s http://localhost:8080/admin/participants -H "Authorization: Bearer $ADMIN"
```

Die vergebenen IDs stehen jeweils als `resourceId` in der Antwort. **Damit jemand seine
eigenen Daten sieht, muss dieselbe ID im Realm als `participant_id`-Attribut des Benutzers
stehen** — die Demo-Benutzer aus Schritt 2 tragen 1, 2 und 3, deshalb passt die Reihenfolge
oben zu `admin` und `testuser`.

Verknüpft wird **kein** Benutzerkonto: Die Identität kommt aus dem Token, und die Tabelle
`user` stammt aus der Zeit davor. Eine Selbstregistrierung ist E1-01.

> Bis B-21 stand hier ein `INSERT` von Hand, mit dem Hinweis, dass solche Zeilen in keinem
> Event stehen und beim nächsten `POST /admin/projections/participant_read_model/rebuild`
> verschwinden. Über den Command angelegte Teilnehmer überstehen einen Neuaufbau.
> Für die E2E-Tests, die keine Admin-Route benutzen wollen, liegt der alte Weg noch als
> [`database/seed-demo-participants.sql`](database/seed-demo-participants.sql) bereit.

## 4. Ein Tippjahr aufsetzen

Jeder Command trägt einen `Idempotency-Key`, jeder antwortet mit `202` und einer
`resourceId`.

Ab hier weiter mit dem `api()`-Helper aus Schritt 3.

**B-10 — Tippjahr anlegen**

```bash
api POST /admin/tipp-years \
  '{"name":"Tippjahr 2026","startDate":"2026-01-01","endDate":"2026-12-31","ticketCostPerRow":1.20}'
```

```json
{"commandId":"8f14e45f-…","status":"accepted","resourceId":1,"timestamp":"…"}
```

**B-14 — Tippperioden festlegen.** Sie müssen im Tippjahr liegen und dürfen sich nicht
überlappen. Eine einzige Periode über das ganze Jahr ergibt „eine Reihe pro Jahr".

```bash
api POST /admin/tipp-years/1/bet-periods '{"name":"Q1 2026","startDate":"2026-01-01","endDate":"2026-03-31"}'
api POST /admin/tipp-years/1/bet-periods '{"name":"Q2 2026","startDate":"2026-04-01","endDate":"2026-06-30"}'
```

**B-11 — Teilnehmer aufnehmen**

```bash
api POST /admin/tipp-years/1/members '{"participantId":1}'
api POST /admin/tipp-years/1/members '{"participantId":2}'
```

**B-06 — Tippreihen zuordnen.** Sechs verschiedene Zahlen aus 1–49; gespeichert wird
aufsteigend.

```bash
api PUT /admin/participants/1/bet-row '{"betPeriodId":1,"numbers":[3,12,19,27,33,45]}'
api PUT /admin/participants/2/bet-row '{"betPeriodId":1,"numbers":[7,8,9,10,11,12]}'
```

Ein zweiter Versuch für dieselbe Periode wird mit `409` abgelehnt — durchgesetzt vom Unique
Key, nicht von einer Prüfung im Code. Eine Korrektur innerhalb der laufenden Periode
verlangt einen ausdrücklichen Grund:

```bash
api PUT /admin/participants/2/bet-row \
  '{"betPeriodId":1,"numbers":[1,2,3,4,5,6],"replaceReason":"falsche Reihe übertragen"}'
```

## 5. Tippjahr starten (B-18)

Ein Tippschein wird nur angenommen, solange das Tippjahr `running` ist:

```bash
api PUT /admin/tipp-years/1/status '{"status":"running"}'
```

**Höchstens ein Tippjahr läuft gleichzeitig** — ein zweites beantwortet denselben Aufruf
mit `409` und nennt das blockierende Jahr. Durchgesetzt wird das vom Unique Key
`tipp_year.running_marker`, nicht von der Prüfung im Handler.

## 6. Tippschein, Ziehungen, Gewinne

**B-12 — Tippschein einreichen.** Bündelt die Reihen aller Teilnehmer, deren Periode den
`periodStart` enthält, kopiert sie als Snapshot nach `ticket_row` und erzeugt je Teilnehmer
eine `Fee`. `total_cost = row_count × drawCount × ticketCostPerRow`.

```bash
api POST /admin/tipp-years/1/tickets \
  '{"periodStart":"2026-01-01","periodEnd":"2026-01-31","drawCount":9,"superzahl":7,"lotteryReference":"LOT-2026-01"}'
```

Der Snapshot ist der Punkt: eine spätere Korrektur einer `BetRow` verändert bereits
eingereichte Scheine nicht.

**B-08 — Ziehung eintragen.** Doppeltes Ziehungsdatum → `409`. Superzahl 0–9.

```bash
api POST /admin/draws '{"tippYearId":1,"drawDate":"2026-01-07","numbers":[3,12,19,27,40,41],"superzahl":7}'
```

**B-09 — Gewinne eintragen.** Der Betrag ist der Gewinn des *gesamten* Scheins. Die Treffer
je Reihe rechnet das System aus den Gewinnzahlen und den Reihen-Snapshots; die Verteilung
läuft in ganzen Cent über `EvenSplit`.

```bash
api PUT /admin/draws/1/winnings '{"totalAmount":123.45}'
```

Optional lässt sich der Betrag nach Gewinnklassen aufschlüsseln; ohne diese Angabe rechnet
das System die Treffer selbst und verteilt die Summe darauf:

```bash
api POST /admin/draws '{"tippYearId":1,"drawDate":"2026-01-10","numbers":[3,12,19,33,44,45],"superzahl":7}'
api PUT /admin/draws/2/winnings \
  '{"totalAmount":500.00,"winningClasses":[{"winningClass":5,"amount":300.00}]}'
```

**B-07 — Zahlung buchen.** Die Fee-IDs liefert `GET /admin/fees`.

```bash
curl -s http://localhost:8080/admin/fees -H "Authorization: Bearer $ADMIN"

api PUT /admin/fees/1/payment \
  '{"paymentStatus":"paid","paidAt":"2026-01-20 10:00:00","paymentMethod":"bank_transfer"}'
```

## 7. Jahresausschüttung

Ausschütten geht nur aus dem Status `closed` und nur einmal:

```bash
api PUT /admin/tipp-years/1/status '{"status":"closed"}'
```

**B-13 — Ausschüttung buchen.** `confirm` fehlt oder ist `false` → `409`: eine Ausschüttung
lässt sich nicht rückgängig machen und wird deshalb nie angenommen, sondern nur bestätigt.

```bash
api POST /admin/tipp-years/1/payout '{"confirm":true,"note":"Jahresabschluss 2026"}'
```

Verteilt wird **gleichmäßig auf alle Teilnehmer des Tippjahres**, unabhängig davon, wie
viele Perioden jemand bezahlt hat. Die Rundungsdifferenz geht auf den ersten Anteil.

## 8. Teilnehmersicht

Mit dem Token von `testuser` (`participant_id: 2`):

```bash
curl -s http://localhost:8080/participants/2/bet-row       -H "Authorization: Bearer $USER"
curl -s http://localhost:8080/participants/2/memberships   -H "Authorization: Bearer $USER"
curl -s http://localhost:8080/participants/2/fees          -H "Authorization: Bearer $USER"
curl -s http://localhost:8080/participants/2/payout-share  -H "Authorization: Bearer $USER"
curl -s http://localhost:8080/tipp-years/1/draws           -H "Authorization: Bearer $USER"
```

Ein Zugriff auf fremde Daten wird mit `403` abgelehnt — auch mit dem Admin-Token:

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  http://localhost:8080/participants/1/fees -H "Authorization: Bearer $USER"   # 403
```

Die Identität kommt aus dem Token, nie aus dem Pfad. Der Admin hat eigene Endpunkte —
sonst wären die Teilnehmerrouten eine zweite, leisere Admin-API.

## 9. Betrieb ansehen

```bash
# OPS-01: Was ist aus einem Command geworden?
curl -s http://localhost:8080/commands/8f14e45f-… -H "Authorization: Bearer $ADMIN"

# OPS-03: Event-Historie eines Aggregats
curl -s http://localhost:8080/admin/audit/tipp_year/1 -H "Authorization: Bearer $ADMIN"

# OPS-04: Projektionen überwachen und neu aufbauen
curl -s http://localhost:8080/admin/projections -H "Authorization: Bearer $ADMIN"
curl -s -X POST http://localhost:8080/admin/projections/tipp_year_read_model/rebuild \
  -H "Authorization: Bearer $ADMIN"
```

**OPS-02 ausprobieren:** denselben Command zweimal mit demselben `Idempotency-Key`
schicken. Der zweite Aufruf führt nichts aus, sondern liefert die gespeicherte Antwort mit
ihrem ursprünglichen Statuscode und dem Header `Idempotent-Replay: true`.

Ein Rebuild zieht nach unten durch: `participant` zu leeren leert über
`ON DELETE CASCADE` auch `membership`, `bet_row` und `fee`, also werden die abhängigen
Projektionen mit aufgebaut. Die Antwort listet alle tatsächlich neu aufgebauten.

## 10. Tests und Prüfungen

Tests laufen in einer **eigenen** Umgebung mit eigener Datenbank:

```bash
make test-db-start        # MariaDB 11.3 auf Port 3307, Schema geladen
make test-docker          # phpunit --testdox
make phpstan-docker
make test-db-stop
```

> **Nicht im `php`-Container testen.** Dort ist `DB_DATABASE` die Entwicklungsdatenbank,
> und die Integration-Suite leert vor jedem Test jede Tabelle — ein Lauf würde das
> Tippjahr aus diesem Durchstich wegräumen. `IntegrationTestCase` lehnt jede Datenbank ab,
> deren Name nicht auf `_test` endet, und überspringt sich mit einem Hinweis.

Nur lesende Prüfungen sind im Dev-Container unbedenklich:

```bash
docker-compose exec php vendor/bin/phpstan analyse
docker-compose exec php vendor/bin/phpcs --standard=PSR12 src tests public config
```

Die Integrationstests **überspringen sich selbst**, wenn keine Datenbank erreichbar ist.
Eine grüne Ausgabe ohne laufende Datenbank beweist deshalb nichts über die Persistenz —
auf die Zeile `Tests: N … Skipped: N` achten, nicht auf den Exit-Code.

Das Frontend hat eigene Suiten (Vitest, Playwright) — siehe [FRONTEND.md](FRONTEND.md).

## Häufige Probleme

**`401` auf jede Route** — Token abgelaufen (Lebensdauer 60 Minuten) oder für den falschen
Realm ausgestellt. Neu holen, siehe Schritt 2.

**`503` statt `401`** — die API erreicht Keycloak nicht.

```bash
docker-compose exec php curl -s http://keycloak:8080/realms/betting-game | head -c 100
```

Das Backend spricht Keycloak unter dem internen Namen `keycloak:8080` an, das Frontend
unter `localhost:8090`.

**`409` beim Tippschein** — das Tippjahr ist nicht `running`, siehe Schritt 5.

**`409` bei einer Tippreihe** — für diese Periode existiert bereits eine. Mit
`replaceReason` ersetzen oder die nächste Periode wählen.

**„Connection refused" zur Datenbank**

```bash
docker-compose ps
docker-compose logs db
```

**Schema neu laden** — `make db-reset` (löscht nichts, spielt `schema.sql` erneut ein;
für einen wirklich leeren Stand `docker-compose down -v`).

## Weiter

- [USER_STORIES.md](USER_STORIES.md) — was das System fachlich kann, Story für Story
- [ARCHITECTURE.md](ARCHITECTURE.md) — Schichten, Event Sourcing, offene Punkte
- [KEYCLOAK.md](KEYCLOAK.md) — Benutzer, Rollen, Token-Prüfung
- [DOCKER.md](DOCKER.md) — Stack, Tuning, Troubleshooting
- [betting_game_api.yaml](betting_game_api.yaml) — der vollständige API-Vertrag
