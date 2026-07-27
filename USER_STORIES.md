# User Stories

Arbeitsdokument: Welche fachlichen Anforderungen bildet das System ab, über welchen
Endpunkt, auf welchen Tabellen — und was davon ist bereits implementiert.

Abgeleitet aus [betting_game_api.yaml](betting_game_api.yaml) (v1.1.0) und
[betting_game_er_extended.mermaid](betting_game_er_extended.mermaid).
Stand: 2026-07-27.

## Rollen

| Rolle | Keycloak | Beschreibung |
|---|---|---|
| **Teilnehmer** | `user` | Tippt, sieht eigene Daten; `participant_id` kommt als JWT-Claim |
| **Administrator** | `admin` | Verwaltet Spiele, Ereignisse, Ergebnisse, Teilnehmer, Gebühren |
| **Betreiber** | `admin` | Betriebssicht auf Event Sourcing (Audit, Projektionen) |
| **System** | — | Zeitgesteuerte Abläufe (Gebührenlauf, Deadline-Erinnerungen) |

## Status-Legende

| Symbol | Bedeutung |
|---|---|
| 🟢 | Route + Handler + Persistenz vorhanden — über HTTP nutzbar |
| 🟠 | Route erreichbar, **Handler ist noch ein Stub** — validiert und quittiert mit `202`, schreibt aber nichts |
| 🔵 | In der API spezifiziert, noch nicht implementiert |

Maßgeblich für 🟢 ist der Eintrag in [Router.php](src/Presentation/Router/Router.php)
zusammen mit einem Handler, der tatsächlich persistiert. Der Routen-Bestand ist durch
[RouterTest.php](tests/Unit/Presentation/RouterTest.php) abgesichert.

---

## Epic 1 — Registrierung & Profil

| ID | Story | Endpunkt | ER-Modell | Status |
|---|---|---|---|---|
| **US-01** | Als **neuer Nutzer** möchte ich mich selbst als Teilnehmer registrieren, damit ich ohne Admin-Eingriff starten kann. | `POST /registrations` | **Participant** (`pending_approval`), **EventStream**, opt. **GameParticipation** | 🔵 |
| **US-02** | Als **Teilnehmer** möchte ich mein Profil einsehen, damit ich meine hinterlegten Daten kenne. | `GET /participants/{id}` | **Participant** + **User** (optional) + Aggregate | 🔵 |
| **US-03** | Als **Teilnehmer** möchte ich Anzeigename und Benachrichtigungseinstellungen ändern. | `PUT /participants/{id}` | **Participant**`.display_name`, `.version`; **NotificationPreference** | 🔵 |
| **US-04** | Als **Teilnehmer** möchte ich meine personenbezogenen Daten exportieren (DSGVO Art. 20). | `GET /participants/{id}/data-export` | **Participant** + **GameParticipation** + **Prediction** + **ParticipantScore** + **Fee** | 🔵 |
| **US-05** | Als **Teilnehmer** möchte ich die Löschung meiner Daten verlangen (DSGVO Art. 17). | `DELETE /participants/{id}` | Pseudonymisiert **Participant**, löst `user_id` — Tipps/Punkte bleiben anonymisiert erhalten | 🔵 |

**Akzeptanzkriterien US-05:** Kein physisches Löschen (Event Sourcing); `409`, solange offene
Gebühren bestehen.

---

## Epic 2 — Spiele und Ereignisse entdecken

| ID | Story | Endpunkt | ER-Modell | Status |
|---|---|---|---|---|
| **US-06** | Als **Teilnehmer** möchte ich beitrittsfähige Spiele finden, damit ich weiß, wo ich mitmachen kann. | `GET /games` | **BettingGame** + **GameType**, `joinable` aus **GameParticipation** | 🔵 |
| **US-07** | Als **Teilnehmer** möchte ich Details und Gebührenbedingungen eines Spiels sehen, bevor ich beitrete. | `GET /games/{bettingGameId}` | **BettingGame**`.base_fee`, `.fee_period_days` | 🔵 |
| **US-08** | Als **Teilnehmer** möchte ich die Ereignisse eines Spiels mit Tippschluss sehen, damit ich weiß, worauf ich tippen kann. | `GET /games/{bettingGameId}/events` | **Event** + eigene **Prediction** | 🔵 |

**Warum zentral:** Ohne US-08 ist die Tippabgabe (Epic 3) blind — der Teilnehmer kennt die
`event_id` sonst nicht. Diese Story war in v1.0 der größte Bruch im Ablauf.

---

## Epic 3 — Tippabgabe

| ID | Story | Endpunkt | ER-Modell | Status |
|---|---|---|---|---|
| **US-09** | Als **Teilnehmer** möchte ich für ein Ereignis einen Tipp abgeben, damit ich am Spiel teilnehme. | `POST /participants/{id}/events/{eventId}/predictions` | **Prediction** + **EventStream** (`aggregate_type='Prediction'`) | 🟢 |
| **US-10** | Als **Teilnehmer** möchte ich meinen Tipp bis zum Tippschluss ändern, damit ich auf neue Infos reagieren kann. | `PUT /participants/{id}/predictions/{predictionId}` | **Prediction**`.prediction_data`, `.updated_at`, `.version` | 🟢 |
| **US-11** | Als **Teilnehmer** möchte ich alle meine Tipps gefiltert einsehen, damit ich den Überblick behalte. | `GET /participants/{id}/predictions` | **Prediction** ⋈ **Event** ⋈ **Result** | 🟢 |
| **US-12** | Als **Teilnehmer** möchte ich zu einem Tipp Ergebnis und Punkte sehen, damit ich die Bewertung nachvollziehe. | `GET /participants/{id}/predictions/{predictionId}` | + **Result**, **ParticipantScore** | 🟢 |
| **US-13** | Als **Teilnehmer** möchte ich nach Tippschluss die Tipps der anderen sehen, damit das Mitfiebern funktioniert. | `GET /participants/{id}/events/{eventId}/predictions/peers` | **Prediction** aller Teilnehmer des Spiels ⋈ **Participant** | 🔵 |
| **US-63** | Als **Administrator** möchte ich alle Tipps über alle Teilnehmer hinweg einsehen, damit ich Auffälligkeiten prüfen kann. | `GET /admin/predictions` | **Prediction** ohne Teilnehmerfilter, mit Pagination | 🟢 |

**Akzeptanzkriterien:**
- US-09: `409` bei überschrittener `Event.deadline`; UK `(participant_id, event_id)` verhindert Doppeltipp
- US-10: `409` nach Deadline; Optimistic Locking über `Prediction.version`
- US-13: `409`, solange `Event.deadline` in der Zukunft liegt — sonst wäre Abschreiben möglich
- `status` (`pending`/`submitted`/`evaluated`) ist **kein Feld**, sondern abgeleitet aus Existenz von **Result**/**ParticipantScore**; `isEditable` aus `deadline` vs. `now()`

---

## Epic 4 — Punkte, Gewinne, Rangliste

| ID | Story | Endpunkt | ER-Modell | Status |
|---|---|---|---|---|
| **US-14** | Als **Teilnehmer** möchte ich meine Punkte und Gewinne inkl. Summe sehen, damit ich meinen Erfolg einschätze. | `GET /participants/{id}/scores` | **ParticipantScore** ⋈ **BettingGame** ⋈ **Event**; Summary = `SUM`/`COUNT DISTINCT` | 🟢 |
| **US-15** | Als **Teilnehmer** möchte ich die Rangliste eines Spiels sehen, damit ich meine Position kenne. | `GET /participants/{id}/games/{gameId}/leaderboard` | Aggregation **ParticipantScore** ⋈ **Participant** | 🟢 |
| **US-16** | Als **Teilnehmer** möchte ich den Verlauf meiner Platzierung sehen, damit ich meine Entwicklung erkenne. | `.../leaderboard/history` | Zeitreihe über **ParticipantScore**`.calculated_at` | 🔵 |

**Hinweis:** `rank` existiert in keiner Tabelle. Umgesetzt ist es in
[LeaderboardReadModelRepository.php](src/Infrastructure/Persistence/LeaderboardReadModelRepository.php)
als laufende Nummer über der sortierten Aggregation (`points DESC, prize DESC, name ASC`) —
ohne Fensterfunktion, dafür deterministisch bei Punktegleichstand. `pointsEarned` und
`prizeAmount` sind beide nullable, weil **ParticipantScore** Sport- **und** Lotteriespiele bedient.

---

## Epic 5 — Spielteilnahme

| ID | Story | Endpunkt | ER-Modell | Status |
|---|---|---|---|---|
| **US-17** | Als **Teilnehmer** möchte ich einem Spiel beitreten und Bedingungen akzeptieren, damit ich mittippen kann. | `POST /participants/{id}/games/{gameId}/participation` | **GameParticipation** (`joined_at`, `status`), opt. **Fee** | 🟢 |
| **US-18** | Als **Teilnehmer** möchte ich ein Spiel verlassen, damit ich nicht weiter zahlungspflichtig bin. | `DELETE .../participation` | `GameParticipation.left_at`, `status='ended'` — kein DELETE | 🟢 |
| **US-19** | Als **Teilnehmer** möchte ich alle meine Teilnahmen mit Status, Punktestand und Zahlungsstatus sehen. | `GET /participants/{id}/participations` | **GameParticipation** ⋈ **BettingGame** ⋈ **GameType** ⋈ **Fee** | 🟢 |

**ER-Bezug:** Dieses Epic ist der Grund für die Zwischentabelle **GameParticipation** — die
ursprüngliche `Participant }o--o{ BettingGame`-Beziehung konnte `joinedAt` und `status` nicht
tragen. `409` bei US-17 entspricht dem UK `(participant_id, betting_game_id)`.

---

## Epic 6 — Gebühren

| ID | Story | Endpunkt | ER-Modell | Status |
|---|---|---|---|---|
| **US-20** | Als **Teilnehmer** möchte ich offene und bezahlte Gebühren mit Zeiträumen sehen, damit ich meinen Zahlungsstand kenne. | `GET /participants/{id}/fees` | **Fee** ⋈ **BettingGame** | 🔵 |
| **US-21** | Als **Teilnehmer** möchte ich eine Zahlung mit Referenz melden, damit meine Gebühr als beglichen erfasst wird. | `POST /participants/{id}/fees/{feeId}/payment` | `Fee.payment_status='pending'`, `.payment_method` | 🔵 |
| **US-22** | Als **Administrator** möchte ich alle Gebühren inkl. Rückstände sehen, damit ich die Abrechnung im Griff habe. | `GET /admin/fees` | **Fee** ⋈ **Participant** ⋈ **BettingGame** | 🔵 |
| **US-23** | Als **Administrator** möchte ich Zahlungseingänge bestätigen oder Gebühren erlassen. | `PUT /admin/fees/{feeId}/payment` | `Fee.payment_status='paid'\|'waived'`, `.paid_at` | 🔵 |
| **US-24** | Als **System** möchte ich periodisch Gebührensätze erzeugen, damit `feePeriodDays` fachlich wirksam wird. | `POST /admin/games/{gameId}/fees/generate` | **Fee** je aktiver **GameParticipation** | 🔵 |

**Akzeptanzkriterien US-24:** idempotent je `periodStart` — ein zweiter Lauf für dieselbe
Periode erzeugt keine Doppelgebühr. Aufruf durch Scheduler oder Admin.

**Lücke im Bestand:** `BettingGame.base_fee` und `fee_period_days` existierten in v1.0 ohne
jeden Prozess dahinter — die **Fee**-Tabelle war reine Datenhaltung ohne Endpunkt.

---

## Epic 7 — Spielverwaltung (Admin)

| ID | Story | Endpunkt | ER-Modell | Status |
|---|---|---|---|---|
| **US-25** | Als **Administrator** möchte ich ein Spiel mit Typ, Zeitraum, Gebühr und Regelwerk anlegen. | `POST /admin/games` | **BettingGame** + **PointConfiguration** *oder* **PrizeDistribution** | 🟢 |
| **US-26** | Als **Administrator** möchte ich alle Spiele nach Status und Typ gefiltert sehen. | `GET /admin/games` | **BettingGame** ⋈ **GameType** | 🟢 |
| **US-27** | Als **Administrator** möchte ich Spieldetails inkl. Teilnehmer- und Ereigniszahl sehen. | `GET /admin/games/{id}` | + `COUNT` **GameParticipation** / **Event** | 🟢 |
| **US-28** | Als **Administrator** möchte ich ein Spiel nachträglich bearbeiten, damit Korrekturen möglich sind. | `PUT /admin/games/{id}` | **BettingGame**, **PointConfiguration**, **PrizeDistribution** | 🔵 |
| **US-29** | Als **Administrator** möchte ich ein Spiel mit Begründung beenden und die Punktestände finalisieren. | `POST /admin/games/{id}/end` | `status='ended'`, Massenlauf **ParticipantScore** | 🟢 |
| **US-30** | Als **Administrator** möchte ich ein Spiel absagen und Gebühren erstatten. | `POST /admin/games/{id}/cancel` | `status='cancelled'`, **GameParticipation** beenden, **Fee** erlassen | 🔵 |
| **US-31** | Als **Administrator** möchte ich Ergebnisse und Salden exportieren, damit die Abrechnung außerhalb erfolgen kann. | `GET /admin/games/{id}/export` | **ParticipantScore** ⋈ **Fee** ⋈ **Participant** | 🔵 |

**Akzeptanzkriterium US-28:** Änderungen an **PointConfiguration**/**PrizeDistribution**
werden mit `409` abgelehnt, sobald **ParticipantScore**-Sätze existieren — bereits vergebene
Punkte müssen reproduzierbar bleiben.

**Regel ohne ER-Ausdruck:** Ob **PointConfiguration** oder **PrizeDistribution** zulässig ist,
hängt von `GameType.category` ab. Das lässt sich im ER-Diagramm nicht darstellen und gehört
in Applikationslogik bzw. Check-Constraint.

---

## Epic 8 — Ereignisverwaltung (Admin)

| ID | Story | Endpunkt | ER-Modell | Status |
|---|---|---|---|---|
| **US-32** | Als **Administrator** möchte ich Ereignisse mit Datum und Tippschluss zu einem Spiel anlegen. | `POST /admin/games/{gameId}/events` | **Event** | 🔵 |
| **US-33** | Als **Administrator** möchte ich alle Ereignisse eines Spiels mit Tipp- und Ergebnisstand sehen. | `GET /admin/games/{gameId}/events` | **Event** ⋈ **Result**, `COUNT` **Prediction** | 🔵 |
| **US-34** | Als **Administrator** möchte ich ein Ereignis einsehen und korrigieren. | `GET`/`PUT /admin/events/{eventId}` | **Event**`.version` | 🔵 |
| **US-35** | Als **Administrator** möchte ich ein Ereignis absagen und die Punkte entwerten. | `POST /admin/events/{eventId}/cancel` | `Event.status='cancelled'`, **ParticipantScore** entwerten | 🔵 |
| **US-36** | Als **Administrator** möchte ich Spielpläne aus einer externen Quelle importieren. | `POST /admin/games/{gameId}/events/import` | **Event** via `external_event_id` (Upsert) | 🔵 |

**Warum blockierend:** v1.0 hatte für **Event** keinen einzigen Endpunkt, obwohl
`BettingGame ||--o{ Event` die Achse des Datenmodells ist. Ohne dieses Epic lässt sich kein
Spiel befüllen und damit kein Tipp abgeben. `external_event_id` existierte als Feld ohne
zugehörigen Prozess.

**Akzeptanzkriterien:**
- US-32: `deadline <= eventDate`
- US-34: `409`, sobald Tipps bewertet wurden
- US-36: Matching über `externalEventId` → Wiederholung aktualisiert statt zu duplizieren

---

## Epic 9 — Ergebnisse und Bewertung (Admin)

| ID | Story | Endpunkt | ER-Modell | Status |
|---|---|---|---|---|
| **US-37** | Als **Administrator** möchte ich das Ergebnis eines Ereignisses mit Quellenangabe erfassen. | `POST /admin/events/{eventId}/results` | **Result** (UK auf `event_id`) | 🟢 |
| **US-38** | Als **Administrator** möchte ich ein falsches Ergebnis mit Begründung korrigieren. | `PUT /admin/events/{eventId}/results` | **Result**`.updated_at`; Grund → `EventStore.metadata` | 🟢 |
| **US-39** | Als **Administrator** möchte ich die Punkteberechnung für ein Ereignis auslösen. | `POST /admin/events/{eventId}/scores/calculate` | **Prediction** × **Result** × **PointConfiguration** → **ParticipantScore** | 🟠 |
| **US-40** | Als **Administrator** möchte ich Punkte oder Gewinne manuell zuweisen, damit Sonderfälle lösbar sind. | `POST /admin/participants/{id}/scores` | **ParticipantScore** (Upsert wegen UK) | 🟠 |

**Neu in v1.1:** `RecordResultCommand.autoCalculateScores` (Default `true`) — die Berechnung
läuft direkt bei Ergebniserfassung. US-39 bleibt als manueller Nachlauf bestehen, etwa nach
einer Ergebniskorrektur (US-38).

**⚠️ US-39 und US-40 sind Stubs.** `CalculateScoresHandler` und `AwardScoreHandler` prüfen die
Existenz von Ereignis bzw. Teilnehmer, werfen sauber `404` und quittieren mit `202` — schreiben
aber **keinen ParticipantScore**. Die Endpunkte sind erreichbar und vertragskonform, fachlich
passiert noch nichts. Solange das so ist, entstehen Punktestände ausschließlich über
Testdaten oder direkte DB-Inserts.

**Akzeptanzkriterium US-40:** Kollidiert mit UK `(participant_id, event_id)` — muss als Upsert
implementiert sein, nicht als Insert.

---

## Epic 10 — Teilnehmerverwaltung (Admin)

| ID | Story | Endpunkt | ER-Modell | Status |
|---|---|---|---|---|
| **US-41** | Als **Administrator** möchte ich alle Teilnehmer mit Status und Statistiken sehen. | `GET /admin/participants` | **Participant** + Aggregate | 🟢 |
| **US-42** | Als **Administrator** möchte ich einen Teilnehmer anlegen, optional mit Sofortfreigabe. | `POST /admin/participants` | **Participant** + **EventStream** | 🟢 |
| **US-43** | Als **Administrator** möchte ich einen Teilnehmer ohne Benutzerkonto anlegen (Gastspieler). | `POST /admin/participants` mit `userId: null` | `Participant.user_id` nullable | 🔵 |
| **US-44** | Als **Administrator** möchte ich einem Gastspieler später ein Konto zuordnen. | `PUT /admin/participants/{id}/user-link` | `Participant.user_id` setzen (UK) | 🔵 |
| **US-45** | Als **Administrator** möchte ich eine Kontoverknüpfung wieder lösen. | `DELETE /admin/participants/{id}/user-link` | `Participant.user_id = NULL` | 🔵 |
| **US-46** | Als **Administrator** möchte ich Registrierungen freigeben oder ablehnen. | `POST /admin/participants/{id}/approve` | `Participant.is_active` bzw. `GameParticipation.status` | 🟢 |
| **US-47** | Als **Administrator** möchte ich offene Freigaben für ein Spiel sehen. | `GET /admin/games/{gameId}/participants/pending` | **GameParticipation** `status='pending_approval'` | 🟢 |
| **US-48** | Als **Administrator** möchte ich einen Teilnehmer sperren. | `POST /admin/participants/{id}/deactivate` | `Participant.is_active=false` | 🔵 |
| **US-49** | Als **Administrator** möchte ich einen Teilnehmer aus einem Spiel entfernen. | `DELETE /admin/games/{gameId}/participants/{id}` | `GameParticipation.left_at`, `status='removed'` | 🔵 |

**ER-Bezug US-43 bis US-45:** Die Beziehung ist `User |o--|| Participant` — ein Teilnehmer
**kann** ein Konto haben, muss aber nicht. `CreateParticipantCommand.userId` wurde in v1.1
entsprechend von `required` auf optional geändert.

**Offene Abweichung:** Der PHP-seitige `CreateParticipantCommand` verlangt weiterhin ein
`int $userId`. `POST /admin/participants` lehnt eine Anlage ohne `userId` deshalb mit `400` ab —
US-43 bleibt offen, bis Command und `Participant::create()` einen nullbaren Wert annehmen.

---

## Epic 11 — Stammdaten (Admin)

| ID | Story | Endpunkt | ER-Modell | Status |
|---|---|---|---|---|
| **US-50** | Als **Administrator** möchte ich Spieltypen einsehen. | `GET /admin/game-types` | **GameType** | 🔵 |
| **US-51** | Als **Administrator** möchte ich Spieltypen anlegen und pflegen. | `POST /admin/game-types`, `PUT /admin/game-types/{id}` | **GameType** | 🔵 |

**Hinweis:** `category` ist auf `sports`/`lottery` festgelegt, weil daran die Auswahl zwischen
**PointConfiguration** und **PrizeDistribution** hängt. Ein neuer Wert erfordert Codeänderung,
nicht nur einen Stammdatensatz.

---

## Epic 12 — Benachrichtigungen

| ID | Story | Endpunkt | ER-Modell | Status |
|---|---|---|---|---|
| **US-52** | Als **Teilnehmer** möchte ich über nahende Tippschlüsse, neue Ergebnisse und fällige Gebühren informiert werden. | `GET /participants/{id}/notifications` | **Notification** ⋈ **EventStore** (`source_event_id`) | 🔵 |
| **US-53** | Als **Teilnehmer** möchte ich Benachrichtigungen als gelesen markieren. | `POST .../notifications/{id}/read` | `Notification.read_at` | 🔵 |
| **US-54** | Als **Teilnehmer** möchte ich Aktualisierungen live erhalten, statt zu pollen. | `GET .../notifications/stream` (SSE) | **EventPublisher** als Quelle, `Last-Event-ID` = Position | 🔵 |

Das Einstellen der Benachrichtigungen (**NotificationPreference**) läuft über **US-03**,
`PUT /participants/{id}` mit `notificationPreferences`.

**ER-Bezug:** **Notification** ist eine Projektion — `source_event_id` zeigt auf den
**EventStore**-Eintrag, der sie ausgelöst hat, und macht jede Benachrichtigung rückverfolgbar.
**NotificationPreference** ist als eigene 0..1-Tabelle modelliert statt als Spalten am
**Participant**, damit neue Kanäle (E-Mail, Push) ohne Schemaänderung am Kernaggregat
hinzukommen können.

**Warum wichtig:** Die gesamte Schreibseite antwortet mit `202 Accepted`. Ohne Rückkanal hat
der Client keine Möglichkeit zu erfahren, wann eine Änderung wirksam wurde.

---

## Epic 13 — Betrieb: CQRS und Event Sourcing

| ID | Story | Endpunkt | ER-Modell | Status |
|---|---|---|---|---|
| **US-55** | Als **Client** möchte ich den Verarbeitungsstand eines akzeptierten Commands abfragen, damit ich nach `202` nicht blind bin. | `GET /commands/{commandId}` | **CommandLog**`.status` ⋈ **EventStore** | 🔵 |
| **US-56** | Als **Client** möchte ich Commands mit `Idempotency-Key` senden, damit ein Retry keine Doppelbuchung erzeugt. | Header auf **allen** Commands | `CommandLog.idempotency_key` UK, `response_body` wird erneut ausgeliefert | 🔵 |
| **US-57** | Als **Betreiber** möchte ich die Event-Historie eines Aggregats einsehen, damit jede Änderung nachvollziehbar ist. | `GET /admin/audit/{aggregateType}/{aggregateId}` | **EventStore** ⋈ **EventStream** | 🔵 |
| **US-58** | Als **Betreiber** möchte ich Status und Lag aller Projektionen sehen. | `GET /admin/projections` | **ProjectionState** vs. **EventStore**-Head | 🔵 |
| **US-59** | Als **Betreiber** möchte ich eine Projektion neu aufbauen, damit ich nach einem Bug korrigieren kann. | `POST /admin/projections/{name}/rebuild` | Replay **EventStore** → Lesetabellen, **Snapshot** | 🔵 |

**Zusammenhang:** `CommandResponse.commandId` ist der PK von **CommandLog** und zugleich
`EventStore.causation_id` — daher die Kante `CommandLog ||--o{ EventStore`. Ein Command kann
mehrere Events erzeugen (Massenimport, Punkteberechnung), deshalb `o{` und nicht `o|`.
`correlation_id` verbindet mehrere Aggregate zu einem Trace.

**Akzeptanzkriterium US-56:** Trifft ein Command mit bereits bekanntem `idempotency_key` ein,
wird das gespeicherte `response_body` unverändert zurückgegeben — kein zweiter Eintrag im
**EventStore**.

---

## Epic 14 — Sicherheit (querschnittlich)

| ID | Story | Umsetzung | Status |
|---|---|---|---|
| **US-60** | Als **Nutzer** möchte ich mich per SSO anmelden. | OIDC/Keycloak, JWT mit Claim `participant_id` — siehe [KEYCLOAK.md](KEYCLOAK.md) | 🟢 |
| **US-61** | Als **Teilnehmer** möchte ich sicher sein, dass niemand meine Daten sieht. | `403`, wenn `participantId` im Pfad ≠ Claim | 🟢 |
| **US-62** | Als **Betreiber** möchte ich den Admin-Bereich rollengeschützt wissen. | `realm_access.roles` muss `admin` enthalten | 🟢 |

---

## Umsetzungsstand

| Status | Anzahl | Anteil |
|---|---|---|
| 🟢 implementiert | 23 | 37 % |
| 🟠 Route vorhanden, Handler ist Stub | 2 | 3 % |
| 🔵 nur spezifiziert | 38 | 60 % |
| **Summe** | **63** | |

### Nächste sinnvolle Schritte

1. **US-39 und US-40 ausimplementieren** — die Routen stehen, es fehlt die Persistenz in
   **ParticipantScore**. Ohne Punkteberechnung bleibt die Rangliste (US-15) leer, obwohl sie
   technisch funktioniert.
2. **Epic 8 (Ereignisverwaltung)** — ohne diese Stories ist das System fachlich nicht
   betreibbar, weil kein Spiel befüllt werden kann.
3. **Epic 2 (Katalog)** — macht Epic 3 für Teilnehmer erst nutzbar.
4. **Epic 13, US-55/56** — je länger die asynchrone Schreibseite ohne Statusabfrage und
   Idempotenz läuft, desto teurer wird das Nachrüsten.
5. **Epic 6 (Gebühren)** — vollständiger Lebenszyklus für eine Tabelle, die es schon gibt.

### Persistenz-Schicht

Alle 13 Repository-Interfaces haben inzwischen eine Implementierung; **alle acht Controller
lassen sich aus dem DI-Container auflösen**. Sieben Implementierungen sind nachträglich
entstanden, weil sie schlicht fehlten:

| Interface | Implementierung |
|---|---|
| `ParticipantRepositoryInterface` | war unvollständig (2 von 5 Methoden) — ergänzt |
| `ResultRepositoryInterface` | [ResultRepository.php](src/Infrastructure/Persistence/ResultRepository.php) |
| `LeaderboardReadModelRepositoryInterface` | [LeaderboardReadModelRepository.php](src/Infrastructure/Persistence/LeaderboardReadModelRepository.php) |
| `PredictionRepositoryInterface` | [PredictionRepository.php](src/Infrastructure/Persistence/PredictionRepository.php) |
| `BettingGameRepositoryInterface` | [BettingGameRepository.php](src/Infrastructure/Persistence/BettingGameRepository.php) |
| `BettingGameReadModelRepositoryInterface` | [BettingGameReadModelRepository.php](src/Infrastructure/Persistence/BettingGameReadModelRepository.php) |
| `ParticipationReadModelRepositoryInterface` | [ParticipationReadModelRepository.php](src/Infrastructure/Persistence/ParticipationReadModelRepository.php) |
| `ParticipantReadModelRepositoryInterface` | [ParticipantReadModelRepository.php](src/Infrastructure/Persistence/ParticipantReadModelRepository.php) |
| `AdminPredictionReadModelRepositoryInterface` | [AdminPredictionReadModelRepository.php](src/Infrastructure/Persistence/AdminPredictionReadModelRepository.php) |

**Projektionslücke geschlossen:** `JoinGameHandler` schrieb bisher nur ein
`ParticipantJoinedGame`-Event, ohne dass jemand **GameParticipation** befüllte — die Tabelle
wäre dauerhaft leer geblieben und US-19 hätte immer eine leere Liste geliefert.
`ParticipantRepository::save()` wendet Join-, Leave- und Approve-Events jetzt auf die
Projektion an.

Jede Repository-Methode hat inzwischen einen Aufrufer — US-27, US-41 und US-47 wurden
nachträglich über Query-Handler, Controller-Methoden und Routen angeschlossen.

### Offene Punkte

| Problem | Auswirkung |
|---|---|
| `PredictionControllerTest` mockt die `final` Klasse `SubmitPredictionHandler` | 7 Testfehler; entweder `final` entfernen oder gegen ein Interface mocken |
| `CalculateScoresHandler` und `AwardScoreHandler` sind Stubs | US-39 und US-40 quittieren mit `202`, ohne einen **ParticipantScore** zu schreiben |

### ER-Erweiterungen aus v1.1

Drei Entitäten wurden für die neuen Epics ergänzt und sind in
[betting_game_er_extended.mermaid](betting_game_er_extended.mermaid) enthalten:

| Entität | Für | Zweck |
|---|---|---|
| **CommandLog** | Epic 13 | Command-Status nach `202`, Idempotenz über `idempotency_key` |
| **Notification** | Epic 12 | Benachrichtigungsprojektion, rückverfolgbar über `source_event_id` |
| **NotificationPreference** | Epic 12 | Pro Teilnehmer 0..1 Einstellungssatz |

Damit umfasst das Modell 20 Entitäten und 27 Beziehungen; jede Beziehung hat einen
korrespondierenden Fremdschlüssel.
