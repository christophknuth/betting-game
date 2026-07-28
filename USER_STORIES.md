# User Stories

Arbeitsdokument: Welche fachlichen Anforderungen bildet das System ab, über welchen
Endpunkt, auf welchen Tabellen — und was davon ist bereits implementiert.

Abgeleitet aus [betting_game_er_extended.mermaid](betting_game_er_extended.mermaid).
Stand: 2026-07-27.

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
| 🟢 | Route + Handler + Persistenz vorhanden |
| 🟠 | Route erreichbar, Handler noch ein Stub |
| 🔵 | Spezifiziert, noch nicht implementiert |
| ♻️ | Bestehender Code direkt weiterverwendbar (siehe Migrationstabelle) |

---

# Basisversion

## Teilnehmer — nur lesend

| ID | Story | Endpunkt | Datenmodell | Status |
|---|---|---|---|---|
| **B-01** | Als **Teilnehmer** möchte ich meine Tippreihe sehen, damit ich weiß, mit welchen Zahlen ich in der laufenden Periode spiele. | `GET /participants/{id}/bet-row` | **BetRow** ⋈ **BetPeriod** ⋈ **TippYear** | 🔵 ♻️ |
| **B-02** | Als **Teilnehmer** möchte ich meine Teilnahmen im laufenden Tippjahr sehen, damit ich weiß, auf welchen Tippscheinen meine Reihe stand. | `GET /participants/{id}/memberships` | **Membership** ⋈ **TippYear**, **TicketRow** ⋈ **Ticket** | 🔵 ♻️ |
| **B-03** | Als **Teilnehmer** möchte ich meine Zahlungen sehen, damit ich weiß, welche Gebühren offen sind. | `GET /participants/{id}/fees` | **Fee** ⋈ **Ticket** | 🔵 ♻️ |
| **B-04** | Als **Teilnehmer** möchte ich meinen anteiligen Gewinn des Tippjahres sehen, damit ich weiß, was ausgeschüttet wird. | `GET /participants/{id}/payout-share` | **PayoutShare** ⋈ **Payout** ⋈ **TippYear** | 🔵 |
| **B-05** | Als **Teilnehmer** möchte ich den Gewinn des Tippscheins je Ziehung sehen, damit ich den Verlauf des Tippjahres nachvollziehen kann. | `GET /tipp-years/{id}/draws` | **Draw** ⋈ **TicketDrawResult** | 🔵 |

**Akzeptanzkriterien:**

- B-01: `404`, solange dem Teilnehmer für die laufende Periode keine Reihe zugeordnet ist
- B-02: enthält je Tippschein, ob die eigene Reihe darauf stand — bei unterjährigem Beitritt fehlt sie auf früheren Scheinen
- B-04: `200` mit `amount: null`, solange die Jahresausschüttung nicht gebucht ist — die Story ist erst nach B-13 gehaltvoll
- B-05: zeigt den Gewinn des **gesamten** Tippscheins, nicht den eigenen Anteil. Der Anteil entsteht erst bei der Ausschüttung

## Administrator

| ID | Story | Endpunkt | Datenmodell | Status |
|---|---|---|---|---|
| **B-06** | Als **Administrator** möchte ich einem Teilnehmer eine Tippreihe für eine Periode zuordnen. | `PUT /admin/participants/{id}/bet-row` | **BetRow** | 🔵 |
| **B-07** | Als **Administrator** möchte ich den Zahlungsstatus eines Teilnehmers für eine Periode setzen, damit die Gebührenlage stimmt. | `PUT /admin/fees/{feeId}/payment` | `Fee.payment_status`, `.paid_at`, `.booked_by` | 🔵 ♻️ |
| **B-08** | Als **Administrator** möchte ich eine Ziehung mit Zahlen und Superzahl eintragen. | `POST /admin/draws` | **Draw** | 🔵 |
| **B-09** | Als **Administrator** möchte ich die Gewinne einer Ziehung eintragen, damit sie in die Jahressumme eingehen. | `PUT /admin/draws/{drawId}/winnings` | **TicketDrawResult**, **TicketRowMatch** | 🔵 |

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
| **B-10** | Als **Administrator** möchte ich ein Tippjahr mit Zeitraum und Reihenpreis anlegen. | `POST /admin/tipp-years` | **TippYear** | 🔵 ♻️ |
| **B-14** | Als **Administrator** möchte ich die Tippperioden eines Tippjahres frei festlegen, damit ich bestimme, wie oft eine Reihe wechseln darf. | `POST /admin/tipp-years/{id}/bet-periods` | **BetPeriod** | 🔵 |
| **B-11** | Als **Administrator** möchte ich einen Teilnehmer in ein Tippjahr aufnehmen. | `POST /admin/tipp-years/{id}/members` | **Membership** | 🔵 ♻️ |
| **B-12** | Als **Administrator** möchte ich den monatlichen Tippschein erfassen, damit Gebühren entstehen und Ziehungen zugeordnet werden können. | `POST /admin/tipp-years/{id}/tickets` | **Ticket**, **TicketRow**, **Fee** je Teilnehmer | 🔵 |
| **B-13** | Als **Administrator** möchte ich die Jahresausschüttung buchen, damit jeder Teilnehmer seinen Anteil erhält. | `POST /admin/tipp-years/{id}/payout` | **Payout**, **PayoutShare** | 🔵 |

**Akzeptanzkriterien:**

- B-14: Perioden müssen innerhalb des Tippjahres liegen und sich untereinander nicht überlappen. Eine einzige Periode über das ganze Jahr ist zulässig und ergibt „eine Reihe pro Jahr"
- B-12: bündelt die Reihen aller Teilnehmer mit aktiver **Membership**, deren **BetPeriod** den `period_start` des Tippscheins enthält; `total_cost = row_count × draw_count × ticket_cost_per_row`; erzeugt je Teilnehmer eine **Fee** über `total_cost / row_count`
- B-12: die Reihen werden als Snapshot in **TicketRow** kopiert — eine spätere Korrektur der **BetRow** verändert eingereichte Scheine nicht
- B-13: `total_winnings` = Summe aller **TicketDrawResult** des Jahres; `share_per_participant = total_winnings / participant_count`; Rundungsdifferenz geht auf den ersten Anteil
- B-13: `409`, wenn das Tippjahr nicht `closed` ist oder bereits eine Ausschüttung existiert

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

| ID | Story | Endpunkt |
|---|---|---|
| **OPS-01** | Verarbeitungsstand eines Commands abfragen | `GET /commands/{commandId}` |
| **OPS-02** | Commands mit `Idempotency-Key` wiederholen können | Header auf allen Commands |
| **OPS-03** | Event-Historie eines Aggregats einsehen | `GET /admin/audit/{type}/{id}` |
| **OPS-04** | Projektionen überwachen und neu aufbauen | `GET /admin/projections`, `.../rebuild` |

---

# Migration des bestehenden Codes

Der Kurswechsel betrifft die Domäne, nicht die Architektur. CQRS, Event Sourcing,
Repository-Struktur, HTTP-Schicht und Testgerüst bleiben unverändert.

## Direkt weiterverwendbar

| Baustein | Anmerkung |
|---|---|
| [Db.php](src/Infrastructure/Persistence/Db.php), [Row.php](src/Infrastructure/Persistence/Row.php) | Typisierter PDO-Zugriff — domänenneutral |
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
| 262 Tests | Domain- und Infrastruktur-Tests bleiben; Sport-spezifische Tests wandern nach E2 |
| [demo/](demo/) | Zeigt Prediction/Result — wird auf Tippreihe/Ziehung umgestellt |
| [betting_game_api.yaml](betting_game_api.yaml) | Auf die Basis neu geschrieben (v2.0, 16 Operationen). Die sportgetriebene v1.1 liegt als [betting_game_api_e2_sports.yaml](betting_game_api_e2_sports.yaml) für E2 bereit |
| PHPStan Level 10, PSR-12 | Unverändert gültig |

---

# Umsetzungsstand

| Stufe | Stories | Implementiert |
|---|---|---|
| Basis | 17 | 3 (Querschnitt B-15 bis B-17) |
| E1 | 9 | 0 |
| E2 | 7 | teilweise vorhanden, aber nicht mehr geroutet |
| Betrieb | 4 | 0 |

Der bestehende Code deckt Sportwetten (E2) recht weit ab — für die Basisversion ist davon vor
allem die Infrastruktur nutzbar, nicht die Fachlogik.
