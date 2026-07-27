# Read-only Demo

Eine sehr kleine, lauffähige Version des Backends zum Ansehen — **nur Prediction und
Result, nur lesend**. Kein Keycloak, keine Anmeldung, keine Command-Endpunkte.

## Starten

```powershell
demo\run.ps1              # Windows, Port 8080
demo\run.ps1 -Port 9000   # anderer Port
```

```bash
demo/run.sh               # Linux/macOS, Port 8080
demo/run.sh 9000
```

Läuft dann auf <http://localhost:8080>. Beenden mit `demo\stop.ps1` bzw. `demo/stop.sh`.

Voraussetzung ist **podman oder docker** — sonst nichts. Zwei Container werden gestartet:
MariaDB mit Schema und Demodaten sowie ein PHP-Webserver. Auf dem Host wird nichts
installiert.

## Endpunkte

| Endpunkt | Zeigt |
|---|---|
| `GET /` | Übersicht der Endpunkte |
| `GET /demo-data` | Was in der Datenbank liegt (Teilnehmer, Ereignisse, Zeilenzahlen) |
| `GET /predictions` | Alle Tipps, mit Pagination |
| `GET /participants/{id}/predictions` | Tipps eines Teilnehmers |
| `GET /participants/{id}/predictions/{predictionId}` | Ein einzelner Tipp |
| `GET /events/{eventId}/result` | Das erfasste Ergebnis eines Ereignisses |

Filter: `participantId`, `eventId`, `bettingGameId`, `status`, `page`, `pageSize`.

Alles andere als `GET` wird mit `405` abgelehnt.

## Demodaten

Drei Teilnehmer (Alice, Bob, Carol), ein Spiel, drei Ereignisse — bewusst so gewählt, dass
alle drei Zustände sichtbar werden, die das Read Model ableitet:

| Ereignis | Zustand | Was man sieht |
|---|---|---|
| 41 — FC Beispiel vs. SV Muster | Ergebnis erfasst | `status: evaluated`, `isEditable: false`, Punkte im `result`-Block |
| 42 — SV Muster vs. TSV Demo | Tippschluss vorbei, kein Ergebnis | `status: pending`, `isEditable: false` |
| 43 — TSV Demo vs. FC Beispiel | noch offen | `status: submitted`, `isEditable: true` |

Weder `status` noch `isEditable` sind Spalten — beide leitet
[PredictionReadModelRepository](src/Infrastructure/Persistence/PredictionReadModelRepository.php)
zur Laufzeit aus Deadline und Ergebnis ab. Genau das macht die Demo sichtbar.

Beispiel:

```bash
curl http://localhost:8080/participants/1/predictions
```

```
TSV Demo vs. FC Beispiel     submitted   isEditable=true    Punkte -
SV Muster vs. TSV Demo       pending     isEditable=false   Punkte -
FC Beispiel vs. SV Muster    evaluated   isEditable=false   Punkte 5
```

## Was hier nicht die echte Anwendung ist

Nur das Routing. [demo/DemoApp.php](demo/DemoApp.php) hat einen eigenen, winzigen Router,
der bewusst **nicht** [Presentation/Router](src/Presentation/Router/Router.php) verwendet —
der kennt auch die Schreibseite. Alles darunter ist Produktionscode: dieselben Query-Handler,
dieselben Repositories, dieselben Read Models.

Ebenfalls anders als im echten Betrieb:

- **Keine Authentifizierung.** Produktiv prüft die Anwendung ein JWT und vergleicht die
  `participant_id` aus dem Token mit der ID im Pfad. Hier ist jeder Tipp für jeden lesbar.
- **`GET /events/{id}/result` gibt es in der API-Spezifikation nicht.** Dort ist für Ergebnisse
  nur `POST` und `PUT` definiert; gelesen werden sie sonst nur eingebettet im Tipp. Für die
  Demo ist der Lesezugriff ergänzt, damit „Result" überhaupt für sich sichtbar wird.
- Fehlerantworten sind knapper als das `ErrorResponse`-Schema der API.

Der Demo-Code läuft unter denselben Prüfungen wie der Rest: PHPStan Level 10 und PSR-12.
