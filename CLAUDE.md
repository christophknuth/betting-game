# CLAUDE.md

Hinweise für Claude Code in diesem Repository.

## Zuerst lesen

**[AGENTS.md](AGENTS.md) enthält die vollständige Projektanleitung** — Domäne, Architektur,
Befehle, Konventionen, Fallstricke. Diese Datei ergänzt nur, was für Claude Code in dieser
Arbeitsumgebung dazukommt. Bei Widerspruch gewinnt AGENTS.md.

## Kurzfassung

PHP-8.3-API für eine **Lotto-6-aus-49-Tippgemeinschaft**. Kein Framework, Onion Architecture,
Event Sourcing + CQRS, MariaDB, Keycloak (OIDC). Ausbaustufe **Basis**: Teilnehmer lesen nur,
der Administrator schreibt alles.

## Umgebung auf diesem Rechner

- **Windows 11, PowerShell.** Der Bash-Tool-Pfad ist Git Bash — beide haben eigene Syntax.
- **PHP, Composer und PHPUnit sind nicht im PATH.** `composer test`, `make phpstan` und
  `vendor/bin/*` schlagen hier direkt fehl. Alles über Docker ausführen:

  ```bash
  docker-compose exec php vendor/bin/phpunit --testdox
  docker-compose exec php vendor/bin/phpstan analyse
  ```

  Läuft der Stack nicht, vorher `docker-compose up -d`. Ohne Docker ist die einzige
  ehrliche Antwort, dass die Prüfung nicht ausgeführt wurde — Ergebnisse nicht schätzen.
- `vendor/` ist ausgecheckt, aber ohne PHP-Binary nutzlos.
- Aktueller Branch: `Refocus-project`. Hauptbranch: `main`.

## Wichtige Dateien zum Einstieg

| Frage | Datei |
|---|---|
| Was macht die Anwendung fachlich? | [USER_STORIES.md](USER_STORIES.md) |
| Welche Endpunkte gibt es? | [src/Presentation/Router/Router.php](src/Presentation/Router/Router.php), [betting_game_api.yaml](betting_game_api.yaml) |
| Wie läuft ein Request ab? | [src/Presentation/Http/Kernel.php](src/Presentation/Http/Kernel.php) |
| Wie ist alles verdrahtet? | [src/Infrastructure/DI/Container.php](src/Infrastructure/DI/Container.php) |
| Welche Tabellen gibt es? | [database/schema.sql](database/schema.sql) |

## Beim Arbeiten beachten

- **Doku-Stand.** Die Dokumentation ist seit dem 2026-07-29 nachgezogen — die Tabelle in
  [AGENTS.md](AGENTS.md) Abschnitt 2 sagt, was gilt.
- **Das Frontend hat kein PHP.** `frontend/` ist eine Vue-3-SPA gegen die Lotto-Endpunkte
  ([FRONTEND.md](FRONTEND.md)). Dort gelten PHPStan und PSR-12 nicht; geprüft wird sie über
  den Build: `docker-compose build frontend` führt `npm run build` aus. `npm run lint` hat
  keine ESLint-Konfiguration und schlägt fehl.
- **PHPStan Level 10 und PSR-12 sind erfüllt.** Änderungen müssen das bleiben lassen.
- **Kommentare erklären das Warum.** Der Bestand ist durchgehend so geschrieben; siehe
  AGENTS.md Abschnitt 6. Neuen Code im selben Ton kommentieren, nicht die Signatur nacherzählen.
- **Sprache:** Code und Commit-Messages Englisch, Projektdokumentation Deutsch.
- **Tests, die sich selbst überspringen.** Ohne erreichbare MariaDB meldet die Integration-Suite
  „skipped", nicht „failed". Eine grüne Ausgabe ohne DB beweist nichts über die Persistenz —
  beim Berichten dazusagen.
- **Nicht anfassen:** `vendor/`, `coverage/`, `.phpunit.cache/`, `var/`.
