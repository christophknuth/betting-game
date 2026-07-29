# User Stories

Arbeitsdokument: Welche fachlichen Anforderungen bildet das System ab, über welchen
Endpunkt, auf welchen Tabellen — und was davon ist bereits implementiert.

Abgeleitet aus [betting_game_er_extended.mermaid](betting_game_er_extended.mermaid).
Stand: 2026-07-29.

## Die Domäne

Verwaltung einer **Lotterie-Tippgemeinschaft für Lotto 6 aus 49**.

Jeder Teilnehmer hat pro **Tippperiode genau eine Tippreihe** aus sechs Zahlen. Sie gilt bis auf
Widerruf und wandert automatisch auf jeden Tippschein — der Teilnehmer tippt nicht pro
Ziehung, sondern einmal pro Periode.

**Wie lang eine Periode ist, legt der Administrator fest.** Eine Periode über das ganze
Tippjahr ergibt „eine Reihe pro Jahr", zwölf Monatsperioden erlauben einen monatlichen
Wechsel. Perioden eines Tippjahres dürfen sich nicht überlappen — sonst wären zwei Reihen
desselben Teilnehmers am selben Tag gültig und der Tippschein wüsste nicht, welche er drucken
soll.

Zum Monatsanfang reicht die Gemeinschaft alle aktiven Reihen als **einen gemeinsamen
Tippschein** bei der Lottogesellschaft ein. Die Kosten dieses Scheins werden auf die
Teilnehmer aufgeteilt und sind im Laufe des Monats zu zahlen.

Gewinne fallen **je Ziehung für den Tippschein als Ganzes** an, werden über das Tippjahr
gesammelt und am Jahresende **gleichmäßig auf alle Teilnehmer** ausgeschüttet — unabhängig
davon, wie viele Perioden jemand bezahlt hat.

Beitritt und Austritt sind regulär **nur zum Jahreswechsel** möglich, im Zuge der
Jahresausschüttung. Die Tippreihe wechselt mit der Periode.

### Festlegungen

| Frage | Entscheidung |
|---|---|
| Tippjahr | Frei definierbarer Zeitraum, kein Kalenderjahr — `TippYear.start_date`/`end_date` |
| Tippperiode | Frei wählbarer Zeitraum innerhalb des Tippjahres — `BetPeriod.start_date`/`end_date`, überlappungsfrei |
| Gewinnverteilung | Gleichmäßig auf alle Teilnehmer des Tippjahres |
| Tippreihe ändern | Eine Reihe je Periode (durchgesetzt über UK `(participant_id, bet_period_id)`) |
| Superzahl | Pro Tippschein, aus der Losnummer — eine Tippreihe sind nur die sechs Zahlen |

Die Periodenlänge ist damit eine **Konfiguration, keine Annahme im Code**. Der Grenzfall „eine
Periode = das ganze Tippjahr" reproduziert exakt das ursprünglich beschriebene Verhalten.

## Ausbaustufen

| Stufe | Inhalt |
|---|---|
| **Basis** | Lotto-Tippgemeinschaft. Teilnehmer nur lesend, Admin pflegt Reihen, Zahlungen, Ziehungen und Gewinne. |
| **E1 — Selbstverwaltung** | Selbstregistrierung, Profil, eigene Reihenwahl, Beitritt/Austritt, Benachrichtigungen |
| **E2 — Sportwetten** | Tippspiel auf Sportergebnisse: Ereignisse mit Tippschluss, Tipp je Ereignis, Punkte, Rangliste |

## Rollen

| Rolle | Keycloak | Beschreibung |
|---|---|---|
| **Teilnehmer** | `user` | Sieht in der Basis ausschließlich eigene Daten — kein Schreibzugriff |
| **Administrator** | `admin` | Pflegt Tippjahr, Reihen, Tippscheine, Ziehungen, Gewinne und Zahlungen |
| **Betreiber** | `admin` | Betriebssicht auf Event Sourcing |

## Status-Legende

| Symbol | Bedeutung |
|---|---|
| 🟢 | Route + Controller + Handler + Persistenz vorhanden, über HTTP getestet |
| 🟠 | Route erreichbar, Handler noch ein Stub |
| 🔵 | Spezifiziert, noch nicht implementiert |
| ♻️ | Bestehender Code direkt weiterverwendbar (siehe Migrationstabelle) |

---

# Basisversion

## Teilnehmer — nur lesend

| ID | Story | Endpunkt | Datenmodell | Status |
|---|---|---|---|---|
| **B-01** | Als **Teilnehmer** möchte ich meine Tippreihe sehen, damit ich weiß, mit welchen Zahlen ich in der laufenden Periode spiele. | `GET /participants/{id}/bet-row` | **BetRow** ⋈ **BetPeriod** ⋈ **TippYear** | 🟢 ♻️ |
| **B-02** | Als **Teilnehmer** möchte ich meine Teilnahmen im laufenden Tippjahr sehen, damit ich weiß, auf welchen Tippscheinen meine Reihe stand. | `GET /participants/{id}/memberships` | **Membership** ⋈ **TippYear**, **TicketRow** ⋈ **Ticket** | 🟢 ♻️ |
| **B-03** | Als **Teilnehmer** möchte ich meine Zahlungen sehen, damit ich weiß, welche Gebühren offen sind. | `GET /participants/{id}/fees` | **Fee** ⋈ **Ticket** | 🟢 ♻️ |
| **B-04** | Als **Teilnehmer** möchte ich meinen anteiligen Gewinn des Tippjahres sehen, damit ich weiß, was ausgeschüttet wird. | `GET /participants/{id}/payout-share` | **PayoutShare** ⋈ **Payout** ⋈ **TippYear** | 🟢 |
| **B-05** | Als **Teilnehmer** möchte ich den Gewinn des Tippscheins je Ziehung sehen, damit ich den Verlauf des Tippjahres nachvollziehen kann. | `GET /tipp-years/{id}/draws` | **Draw** ⋈ **TicketDrawResult** | 🟢 |

**Akzeptanzkriterien:**

- B-01: `404`, solange dem Teilnehmer für die laufende Periode keine Reihe zugeordnet ist
- B-02: enthält je Tippschein, ob die eigene Reihe darauf stand — bei unterjährigem Beitritt fehlt sie auf früheren Scheinen
- B-04: `200` mit `amount: null`, solange die Jahresausschüttung nicht gebucht ist — die Story ist erst nach B-13 gehaltvoll
- B-05: zeigt den Gewinn des **gesamten** Tippscheins, nicht den eigenen Anteil. Der Anteil entsteht erst bei der Ausschüttung

## Administrator

| ID | Story | Endpunkt | Datenmodell | Status |
|---|---|---|---|---|
| **B-06** | Als **Administrator** möchte ich einem Teilnehmer eine Tippreihe für eine Periode zuordnen. | `PUT /admin/participants/{id}/bet-row` | **BetRow** | 🟢 |
| **B-07** | Als **Administrator** möchte ich den Zahlungsstatus eines Teilnehmers für eine Periode setzen, damit die Gebührenlage stimmt. | `PUT /admin/fees/{feeId}/payment` | `Fee.payment_status`, `.paid_at`, `.booked_by` | 🟢 ♻️ |
| **B-08** | Als **Administrator** möchte ich eine Ziehung mit Zahlen und Superzahl eintragen. | `POST /admin/draws` | **Draw** | 🟢 |
| **B-09** | Als **Administrator** möchte ich die Gewinne einer Ziehung eintragen, damit sie in die Jahressumme eingehen. | `PUT /admin/draws/{drawId}/winnings` | **TicketDrawResult**, **TicketRowMatch** | 🟢 |

**Akzeptanzkriterien:**

- B-06: `409`, wenn für diese Periode bereits eine Reihe existiert — Durchsetzung über den UK, nicht über eine Prüfung im Code. Eine Korrektur innerhalb der laufenden Periode braucht einen expliziten Ersetzungsgrund
- B-06: genau sechs verschiedene Zahlen aus 1–49, aufsteigend gespeichert
- B-08: `409` bei doppeltem Ziehungsdatum; Zahlen und Superzahl (0–9) werden gegen dieselben Regeln geprüft
- B-09: rechnet aus `Draw.numbers` und den `TicketRow`-Snapshots die Treffer je Reihe (**TicketRowMatch**) und summiert den Scheingewinn

## Implizit erforderlich

Diese fünf Stories stehen nicht in der Aufgabenliste, aber ohne sie können die Daten für
B-01 bis B-09 gar nicht entstehen. Sie gehören in die Basis und sind in dieser Reihenfolge
abzuarbeiten.

| ID | Story | Endpunkt | Datenmodell | Status |
|---|---|---|---|---|
| **B-10** | Als **Administrator** möchte ich ein Tippjahr mit Zeitraum und Reihenpreis anlegen. | `POST /admin/tipp-years` | **TippYear** | 🟢 ♻️ |
| **B-14** | Als **Administrator** möchte ich die Tippperioden eines Tippjahres frei festlegen, damit ich bestimme, wie oft eine Reihe wechseln darf. | `POST /admin/tipp-years/{id}/bet-periods` | **BetPeriod** | 🟢 |
| **B-11** | Als **Administrator** möchte ich einen Teilnehmer in ein Tippjahr aufnehmen. | `POST /admin/tipp-years/{id}/members` | **Membership** | 🟢 ♻️ |
| **B-12** | Als **Administrator** möchte ich den monatlichen Tippschein erfassen, damit Gebühren entstehen und Ziehungen zugeordnet werden können. | `POST /admin/tipp-years/{id}/tickets` | **Ticket**, **TicketRow**, **Fee** je Teilnehmer | 🟢 |
| **B-13** | Als **Administrator** möchte ich die Jahresausschüttung buchen, damit jeder Teilnehmer seinen Anteil erhält. | `POST /admin/tipp-years/{id}/payout` | **Payout**, **PayoutShare** | 🟢 |
| **B-18** | Als **Administrator** möchte ich den Status eines Tippjahres setzen, damit ich es starten, beenden und eine falsche Buchung korrigieren kann. | `PUT /admin/tipp-years/{id}/status` | **TippYear** | 🟢 |

**Akzeptanzkriterien:**

- B-18: **jeder** Übergang zwischen `planned`, `running`, `closed` und `distributed` ist erlaubt, auch rückwärts — ein zu früh geschlossenes Jahr muss sich wieder öffnen lassen, und die Korrektur gehört in die Event-Historie statt in ein manuelles `UPDATE`
- B-18: **höchstens ein Tippjahr ist gleichzeitig `running`.** `409` mit Nennung des blockierenden Jahres. Durchgesetzt über den Unique Key `tipp_year.running_marker`, nicht über die Prüfung im Handler — die dient nur der Fehlermeldung und hält gegen Nebenläufigkeit nicht
- B-18: `400` bei unbekanntem Status, `409` beim Setzen des bereits gesetzten Status (ein Event, das keine Änderung beschreibt, gehört nicht in die Historie), `404` bei unbekanntem Tippjahr
- B-14: Perioden müssen innerhalb des Tippjahres liegen und sich untereinander nicht überlappen. Eine einzige Periode über das ganze Jahr ist zulässig und ergibt „eine Reihe pro Jahr"
- B-12: bündelt die Reihen aller Teilnehmer mit aktiver **Membership**, deren **BetPeriod** den `period_start` des Tippscheins enthält; `total_cost = row_count × draw_count × ticket_cost_per_row`; erzeugt je Teilnehmer eine **Fee** über `total_cost / row_count`
- B-12: die Reihen werden als Snapshot in **TicketRow** kopiert — eine spätere Korrektur der **BetRow** verändert eingereichte Scheine nicht
- B-13: `total_winnings` = Summe aller **TicketDrawResult** des Jahres; `share_per_participant = total_winnings / participant_count`; Rundungsdifferenz geht auf den ersten Anteil
- B-13: `409`, wenn das Tippjahr nicht `closed` ist oder bereits eine Ausschüttung existiert

## Jahreswechsel — spezifiziert, noch nicht implementiert

Beide Stories bauen auf B-18 auf und gehören zusammen: B-19 legt fest, *was* als Nächstes
läuft, B-20 macht den Wechsel unbeaufsichtigt.

| ID | Story | Endpunkt | Datenmodell | Status |
|---|---|---|---|---|
| **B-19** | Als **Administrator** möchte ich einem laufenden Tippjahr einen Nachfolger zuordnen, damit feststeht, welches Jahr als Nächstes läuft. | `PUT /admin/tipp-years/{id}/successor` | **TippYear**, neue Spalte `successor_id` | 🔵 |
| **B-20** | Als **Betreiber** möchte ich, dass ein abgelaufenes Tippjahr automatisch beendet und der konfigurierte Nachfolger gestartet wird, damit der Wechsel nicht von einer manuellen Buchung abhängt. | kein Endpunkt — geplanter Lauf | **TippYear** | 🔵 |

**Akzeptanzkriterien:**

- B-19: der Nachfolger muss `planned` sein und darf nicht das Jahr selbst sein; sein Zeitraum muss **nach** dem des laufenden Jahres liegen
- B-19: ein Tippjahr ist Nachfolger von höchstens einem anderen — durchzusetzen über einen Unique Key auf `successor_id`, nicht über eine Prüfung im Handler
- B-19: der Nachfolger ist überschreibbar und entfernbar, solange der Wechsel nicht stattgefunden hat
- B-20: „abgelaufen" heißt `end_date < heute` **und** Status `running`
- B-20: der Lauf setzt das Jahr auf `closed` und, falls ein Nachfolger konfiguriert ist, diesen auf `running` — beides über denselben Weg wie B-18, damit die Regel „nur ein laufendes Jahr" und die Event-Historie gleich bleiben
- B-20: der Lauf ist **idempotent** und muss ein zweites Mal folgenlos durchlaufen; er läuft möglicherweise mehrfach parallel, entscheiden muss deshalb der Unique Key
- B-20: ausgeschüttet wird **nicht** automatisch. B-13 verlangt eine ausdrückliche Bestätigung und ist nicht rücknehmbar — das bleibt eine menschliche Entscheidung
- B-20: der Lauf schreibt in die Command-Historie wie jeder andere Schreibvorgang, damit im Nachhinein erkennbar ist, dass eine Automatik gebucht hat und nicht ein Administrator

**Offene Entwurfsfragen:**

- Wo läuft B-20? Ein Cron im `php`-Container ist das Naheliegende; ein Endpunkt, den ein
  externer Scheduler anstößt, wäre testbarer und im Betrieb sichtbarer.
- Was passiert mit einem abgelaufenen Jahr **ohne** Nachfolger? Vorschlag: schließen und
  nichts starten — dann steht der Betrieb still und fällt auf, statt still weiterzulaufen.
- Was, wenn der Nachfolger noch keine Tippperioden hat? Dann nimmt er zwar Tippscheine an,
  aber keine Reihe ist gültig. Vermutlich sollte B-20 in dem Fall nicht starten.

## Querschnitt

| ID | Story | Umsetzung | Status |
|---|---|---|---|
| **B-15** | Als **Nutzer** möchte ich mich per SSO anmelden. | OIDC/Keycloak, `participant_id` als JWT-Claim | 🟢 ♻️ |
| **B-16** | Als **Teilnehmer** möchte ich sicher sein, dass niemand meine Daten sieht. | `403`, wenn `participantId` im Pfad ≠ Claim | 🟢 ♻️ |
| **B-17** | Als **Betreiber** möchte ich den Adminbereich rollengeschützt wissen. | `realm_access.roles` enthält `admin` | 🟢 ♻️ |

---

# E1 — Selbstverwaltung

Alles, was Teilnehmern Schreibzugriff auf ihre eigenen Daten gibt.

| ID | Story | Endpunkt |
|---|---|---|
| **E1-01** | Selbstregistrierung als Teilnehmer | `POST /registrations` |
| **E1-02** | Eigenes Profil sehen und ändern | `GET`/`PUT /participants/{id}` |
| **E1-03** | Eigene Tippreihe zur nächsten Periode selbst wählen | `PUT /participants/{id}/bet-row` |
| **E1-04** | Beitritt zum nächsten Tippjahr beantragen | `POST /tipp-years/{id}/join-requests` |
| **E1-05** | Austritt zum Jahresende erklären | `POST /tipp-years/{id}/leave-requests` |
| **E1-06** | Zahlung selbst melden | `POST /participants/{id}/fees/{feeId}/payment` |
| **E1-07** | Über fällige Gebühren, ausgewertete Ziehungen und die Ausschüttung benachrichtigt werden | `GET .../notifications`, SSE-Stream |
| **E1-08** | Offene Tippgemeinschaften finden und einsehen | `GET /tipp-years` |
| **E1-09** | Eigene Daten exportieren und Löschung verlangen (DSGVO) | `GET .../data-export`, `DELETE /participants/{id}` |

**Warum nicht in der Basis:** E1-03 bis E1-05 verschieben Entscheidungen vom Admin zum
Teilnehmer und brauchen einen Genehmigungsfluss. In der Basis bucht der Admin alles direkt.

---

# E2 — Sportwetten-Tippspiel

Das ursprüngliche Sportergebnis-Tippspiel als zweite Spielart neben der Lotterie.

| ID | Story | Endpunkt |
|---|---|---|
| **E2-01** | Spielart und Punkteregeln verwalten | `GET`/`POST /admin/game-types`, `PointConfiguration` |
| **E2-02** | Sportereignisse mit Tippschluss anlegen und importieren | `POST /admin/games/{id}/events`, `.../events/import` |
| **E2-03** | Tipp je Ereignis abgeben und bis Tippschluss ändern | `POST`/`PUT .../predictions` |
| **E2-04** | Ergebnis erfassen und Punkte berechnen | `POST /admin/events/{id}/results`, `.../scores/calculate` |
| **E2-05** | Rangliste eines Spiels sehen | `GET .../leaderboard` |
| **E2-06** | Tipps der anderen nach Tippschluss sehen | `GET .../predictions/peers` |
| **E2-07** | Spielkatalog durchsuchen | `GET /games`, `/games/{id}/events` |

**Struktureller Unterschied zur Lotterie:** Dort ist ein Tipp `(Teilnehmer, Ereignis)` und pro
Ereignis änderbar. In der Lotterie ist er `(Teilnehmer, Tippperiode)` und nur mit der Periode änderbar.
Beide Modelle nebeneinander zu betreiben heißt, `BetRow` und `Prediction` als getrennte
Aggregate zu führen — nicht, eines zu verallgemeinern.

---

# Betrieb (stufenübergreifend)

| ID | Story | Endpunkt | Status |
|---|---|---|---|
| **OPS-01** | Verarbeitungsstand eines Commands abfragen | `GET /commands/{commandId}` | 🟢 |
| **OPS-02** | Commands mit `Idempotency-Key` wiederholen können | Header auf allen Commands | 🟢 |
| **OPS-03** | Event-Historie eines Aggregats einsehen | `GET /admin/audit/{type}/{id}` | 🟢 |
| **OPS-04** | Projektionen überwachen und neu aufbauen | `GET /admin/projections`, `POST .../{name}/rebuild` | 🟢 |

**Command Log (OPS-01, OPS-02).** Der `Kernel` führt jede als `command` markierte Route unter
dem `command_log` aus. Der `Idempotency-Key` wird **vor** der Ausführung beansprucht — der
Unique Key auf der Spalte entscheidet das Rennen. Erst prüfen und dann ausführen ließe ein
Fenster, in dem zwei parallele Wiederholungen beide durchkommen; genau die Doppelbuchung, gegen
die der Schlüssel existiert. Ein Retry liefert die gespeicherte Antwort mit ihrem
ursprünglichen Statuscode und dem Header `Idempotent-Replay: true`.

Die `commandId` der Antwort ist der Primärschlüssel im `command_log` — der Handler erzeugt zwar
eine eigene, der Kernel überschreibt sie aber mit der protokollierten, damit
`GET /commands/{id}` sie auch findet.

**Ehrlich zur Asynchronität:** Die API beschreibt Commands als asynchron. Diese Implementierung
schreibt synchron — wenn der Aufrufer die `202` hat, ist der Command bereits `completed`.
`projectionsUpToDate` ist deshalb immer `true`. Der Endpunkt bleibt trotzdem sinnvoll: dort
schlägt ein Retry nach, was der erste Versuch erzeugt hat.

**Projektionen (OPS-04).** Sieben Projektoren, je einer pro Read Model. Die Repositories
schreiben ihre Projektion weiterhin synchron beim Speichern — ein Laden direkt danach muss sie
sehen. Die Projektoren sind der *zweite* Weg zu denselben Zeilen: sie spielen das Event-Log
nach.

Zwei Wege zu denselben Tabellen driften auseinander, wenn niemand nachsieht. Deshalb spielt
[ProjectionRebuildTest](tests/Integration/Application/ProjectionRebuildTest.php) ein komplettes
Tippjahr durch die Command-Handler, fotografiert **alle 13** Read-Model-Tabellen, baut aus dem
Event Store neu auf und vergleicht Zeile für Zeile. Einzige Ausnahme:
`ticket_row_match.calculated_at` — das hält fest, *wann* gerechnet wurde, und darf sich bei
einem Neuaufbau ändern.

`ticket_row_match` steht in keinem Event; nur der Scheingewinn wird protokolliert. Der
Projektor rechnet die Zeilen deshalb neu — über denselben Domain-Service
`WinningsDistribution`, den auch der Command-Handler benutzt. Genau dafür wurde er aus dem
Handler herausgezogen.

**Ein Neuaufbau zieht nach unten durch.** Die Read Models hängen über Foreign Keys mit
`ON DELETE CASCADE` zusammen: `participant` zu leeren leert auch `membership`, `bet_row` und
`fee`. Ein Rebuild baut deshalb immer die abhängigen Projektionen mit auf — sonst blieben sie
leer und niemand merkte es. Die Antwort listet alle tatsächlich neu aufgebauten.

---

# Migration des bestehenden Codes

Der Kurswechsel betrifft die Domäne, nicht die Architektur. CQRS, Event Sourcing,
Repository-Struktur, HTTP-Schicht und Testgerüst bleiben unverändert.

## Direkt weiterverwendbar

| Baustein | Anmerkung |
|---|---|
| [Db.php](src/Infrastructure/Persistence/Db.php), [Row.php](src/Support/Row.php) | Typisierter PDO-Zugriff — domänenneutral |
| [Input.php](src/Presentation/Http/Input.php), [JsonResponse.php](src/Presentation/Http/JsonResponse.php), [Request.php](src/Presentation/Http/Request.php) | HTTP-Schicht — domänenneutral |
| [Router.php](src/Presentation/Router/Router.php) | Struktur bleibt, nur die Routen ändern sich |
| [Container.php](src/Infrastructure/DI/Container.php), [Config.php](src/Infrastructure/Config/Config.php) | Verdrahtung |
| [PdoEventStore.php](src/Infrastructure/EventStore/PdoEventStore.php) | Event Store inkl. Optimistic Locking |
| `Domain/Exception/*` | Ausnahmehierarchie |
| [IntegrationTestCase.php](tests/Integration/IntegrationTestCase.php) | Testbasis inkl. Skip-Verhalten |
| `Participant`, `User`, `Fee` | Fachlich unverändert; Fee bekommt `ticket_id` statt `betting_game_id` |

## Umbenannt und umgebaut

| Bisher | Wird zu | Änderung |
|---|---|---|
| `BettingGame` | **TippYear** | Zeitraum + Reihenpreis statt Spieltyp und Gebührenrhythmus |
| `GameParticipation` | **Membership** | Bezug auf Tippjahr statt Spiel |
| `Prediction` | **BetRow** | **Grundlegend**: kein `event_id` mehr, stattdessen `bet_period_id`; sechs Zahlen statt freiem JSON; UK erzwingt eine Reihe pro Periode |
| `Event` | **Draw** | Ziehungsdatum, Zahlen, Superzahl — kein Tippschluss, weil nicht pro Ziehung getippt wird |
| `Result` | Geht in **Draw** auf | Die Ziehung *ist* das Ergebnis. `TicketDrawResult` ist neu und meint den Gewinn, nicht das Ergebnis |
| `ParticipantScore` | **TicketRowMatch** + **PayoutShare** | Treffer je Reihe und Ziehung einerseits, Jahresanteil andererseits |

## Entfällt in der Basis, kehrt in E2 zurück

`GameType`, `PointConfiguration`, `PrizeDistribution`, Leaderboard, Peer-Ansicht der Tipps und
der gesamte Spielkatalog. Der Code bleibt im Repository, wird aber nicht mehr geroutet.

## Neu

**Ticket**, **TicketRow**, **TicketDrawResult**, **TicketRowMatch**, **Payout**, **PayoutShare**.
Das ist der Kern der Lotterie-Logik und hat im bisherigen Modell keine Entsprechung.

## Auswirkung auf den Bestand

| Bereich | Auswirkung |
|---|---|
| Tests | Domain- und Infrastruktur-Tests bleiben; Sport-spezifische Tests wandern nach E2. Aktuell 338 Testmethoden (181 Unit, 157 Integration) |
| `demo/` | Die Nur-Lese-Demo für Prediction/Result ist mit dem Kurswechsel entfallen und nicht ersetzt worden |
| [betting_game_api.yaml](betting_game_api.yaml) | Auf die Basis neu geschrieben (v2.2.0, 19 Pfade, 21 Operationen; `/health` steht bewusst nicht darin). Die sportgetriebene v1.1 liegt als [betting_game_api_e2_sports.yaml](betting_game_api_e2_sports.yaml) für E2 bereit |
| PHPStan Level 10, PSR-12 | Unverändert gültig |

---

# Umsetzungsstand

| Stufe | Stories | Fertig |
|---|---|---|
| Basis | 17 | **17** — alle |
| E1 | 9 | 0 |
| E2 | 7 | teilweise vorhanden, aber nicht mehr geroutet |
| Betrieb | 4 | **4** — alle |

Die Basisversion ist damit vollständig: 17 Routen, jede über HTTP getestet. Der bestehende
Code deckt Sportwetten (E2) recht weit ab — davon ist für die Basis vor allem die Infrastruktur
nutzbar, nicht die Fachlogik.

## Schichten je Story

| Story | Route | Command | Query |
|---|---|---|---|
| B-01 | `GET /participants/{id}/bet-row` | — | `GetBetRowHandler` |
| B-02 | `GET /participants/{id}/memberships` | — | `GetMembershipsHandler` |
| B-03 | `GET /participants/{id}/fees` | — | `GetParticipantFeesHandler` |
| B-04 | `GET /participants/{id}/payout-share` | — | `GetPayoutShareHandler` |
| B-05 | `GET /tipp-years/{id}/draws` | — | `GetDrawsHandler` |
| B-06 | `PUT /admin/participants/{id}/bet-row` | `AssignBetRowHandler` | — |
| B-07 | `PUT /admin/fees/{id}/payment`, `GET /admin/fees` | `RecordFeePaymentHandler` | `GetFeesHandler` |
| B-08 | `POST /admin/draws` | `RecordDrawHandler` | — |
| B-09 | `PUT /admin/draws/{id}/winnings` | `RecordDrawWinningsHandler` | — |
| B-10 | `POST`/`GET /admin/tipp-years` | `CreateTippYearHandler` | `GetTippYearsHandler` |
| B-11 | `POST /admin/tipp-years/{id}/members` | `AddMemberHandler` | — |
| B-12 | `POST /admin/tipp-years/{id}/tickets` | `SubmitTicketHandler` | — |
| B-13 | `POST /admin/tipp-years/{id}/payout` | `DistributePayoutHandler` | — |
| B-18 | `PUT /admin/tipp-years/{id}/status` | `ChangeTippYearStatusHandler` | — |
| B-14 | `POST`/`GET /admin/tipp-years/{id}/bet-periods` | `CreateBetPeriodHandler` | `GetBetPeriodsHandler` |

Handler geben `CommandResult` bzw. `QueryResult` zurück; Commands antworten mit `202`, Queries
mit `200`. Die `commandId` der Antwort ist inzwischen der Primärschlüssel im `command_log`
(OPS-01) — mit `EventStore.causation_id` ist sie weiterhin nicht verknüpft.

**Zwei Lücken.** Der Lebenszyklus des Tippjahres (`planned → running → closed`) ist im
Aggregat vollständig durchgesetzt, hat aber **weder Command noch Route**: `TippYear::start()`
und `close()` werden nur aus Tests aufgerufen. Ebenso gibt es keinen Endpunkt, der einen
`Participant` anlegt — Selbstregistrierung ist E1-01. Über HTTP allein lässt sich ein
Tippjahr damit anlegen, aber nicht in `running` bringen, und ohne das nimmt es keinen
Tippschein an. Für einen Durchstich siehe [QUICKSTART.md](QUICKSTART.md), Schritte 3 und 5.

## HTTP-Schicht

`Kernel` erledigt Routing, Authentifizierung, Rollenprüfung und Fehlerabbildung; `index.php` ist
nur noch die Brücke zu PHPs Globals. Dadurch lässt sich die ganze Kette ohne Webserver testen.

`ErrorMapper` ist die einzige Stelle, die HTTP-Codes kennt — Handler werfen Domänen-Ausnahmen:

| Ausnahme | HTTP |
|---|---|
| `UnauthorizedAccessException` | 403 |
| `EntityNotFoundException` | 404 |
| `InvalidInputException`, `InvalidArgumentException` | 400 |
| `BusinessRuleViolationException` (inkl. `DuplicateEntryException`) | 409 |
| `ConcurrencyException` | 409 |
| alles andere | 500 (Meldung nur im Debug-Modus) |

`DuplicateEntryException`: Regeln wie „eine Reihe pro Teilnehmer und Periode" stehen im Schema,
nicht im Code. Ohne sie müsste die Application-Schicht `PDOException` fangen und SQLSTATE lesen,
um zu erkennen, dass eine *Fachregel* abgelehnt hat.

**Zugriffsschutz.** Die Identität kommt aus dem Token, nie aus dem Pfad — sonst würde die
Eigentumsprüfung immer sich selbst bestätigen. `Authorization::requireSelf` ist bewusst streng:
auch ein Admin kommt dort nicht durch, denn dafür gibt es die Admin-Endpunkte. Die Prüfung läuft
**vor** der Query, sonst verriete ein 404 bereits, dass zu einem fremden Teilnehmer nichts
existiert.

## Token-Signatur

Die Identität kommt aus dem Token — also hängt jede Regel oben daran, dass das Token wirklich
von Keycloak stammt. Bis [TokenVerifier](src/Infrastructure/Auth/TokenVerifier.php) existierte,
las die Anwendung die Claims und glaubte sie: jeder konnte sich eine `participant_id` und die
Rolle `admin` ausstellen, und B-15 bis B-17 waren damit Dekoration.

Geprüft wird, in dieser Reihenfolge:

| Prüfung | Wogegen |
|---|---|
| `alg` gegen eine Allowlist | `alg: none`; HS256, mit dem öffentlichen Schlüssel als „Secret" |
| Signatur gegen den Public Key aus dem JWKS | gefälschte und nachträglich geänderte Tokens |
| `exp`, `nbf`, `iat` (mit Leeway) | abgelaufene Tokens; Uhrendrift |
| `iss` exakt | ein gültig signiertes Token des falschen Realms |
| `aud`, wenn konfiguriert | ein Token für einen anderen Client |

**Die Allowlist kann nur asymmetrische Verfahren enthalten** — der Konstruktor lehnt alles andere
ab. Beide klassischen Fälschungen scheitern damit an derselben Stelle, und ein `HS256` in der
Konfiguration fällt beim Start auf statt auf dem Request, der damit gefälscht worden wäre.

Der Schlüssel kommt **immer aus dem Key Set, nie aus dem Token**. Eine unbekannte `kid` löst
genau einen Refetch aus (Keycloak signiert mit dem neuen Schlüssel, sobald es rotiert) — der
gedrosselt ist, denn die `kid` steht im Token, das der Aufrufer schreibt.

Nicht erreichbares Keycloak ist **503, nicht 401**: ein 401 würde jedem Client sagen, sein
intaktes Token sei kaputt, und ihn zur Neuanmeldung genau dorthin schicken, wo wir schon wissen,
dass es nicht geht. Ein zuletzt bekanntes Key Set überlebt einen Ausfall — Signaturschlüssel
rotieren im Monatsrhythmus, Tokens laufen binnen einer Stunde ab.

ES\* und PS\* werden abgelehnt statt durchgewunken; Keycloaks Standard ist RS256.

## Geldbeträge

`EvenSplit` teilt in ganzen Cent und legt den Rest auf den ersten Anteil. In Fließkomma zu
teilen und je Anteil zu runden erzeugt oder vernichtet Geld: 100,00 € auf drei ergibt dreimal
33,33 € und ein Cent verschwindet. Betrifft die Jahresausschüttung (B-13) und die Verteilung
eines Scheingewinns auf die Reihen (B-09).

## Persistenzschicht

| Aggregat | Repository | Projektionen, die sein Stream schreibt |
|---|---|---|
| `TippYear` | `TippYearRepository` | `tipp_year`, `membership`, `payout`, `payout_share` |
| `BetPeriod` | `BetPeriodRepository` | `bet_period` |
| `BetRow` | `BetRowRepository` | `bet_row` |
| `Ticket` | `TicketRepository` | `ticket`, `ticket_row` |
| `Draw` | `DrawRepository` | `draw`, `ticket_draw_result`, `ticket_row_match` |
| `Fee` | `FeeRepository` | `fee` |
| `Participant` | `ParticipantRepository` | `participant` |

**Zwei Entscheidungen, die man beim Lesen sonst übersieht:**

Ein neues Aggregat wird mit einem reinen `INSERT` geschrieben, ein geladenes mit `UPDATE`.
Kein `ON DUPLICATE KEY UPDATE` — das trifft *jeden* Unique Key und würde bei einer zweiten
Tippreihe für dieselbe Periode die vorhandene Reihe stillschweigend überschreiben, statt den
409 aus dem Akzeptanzkriterium zu B-06 auszulösen.

Append und Projektionsschreiben laufen in **einer** Transaktion. Sonst bliebe nach einer vom
Unique Key abgelehnten Reihe ein `bet_row.assigned`-Event im Store stehen, das keine Zeile
beschreibt.

## Tests

338 Testmethoden (181 Unit in 19 Dateien, 157 Integration in 16 Dateien). Die
Integrationstests brauchen eine Datenbank und überspringen sich selbst, wenn keine
erreichbar ist — `make test` bleibt also auch ohne grün.

Die Handler werden mit **echten** Repositories gegen eine echte Datenbank getestet. Mit
gemockten Repositories bliebe kaum etwas übrig: welche Zeilen eine Query liefert, welcher
Unique Key greift und ob eine Projektion konsistent endet, kann nur eine Datenbank beantworten.

```sh
make test-db-start     # MariaDB 11.3 mit geladenem Schema
make test-integration
make test-db-stop
```
