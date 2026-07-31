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

## Analysen und Tests ausführen

PHP, Composer und PHPUnit müssen nicht lokal installiert sein — sie laufen im Container:

```bash
docker-compose exec php vendor/bin/phpunit --testdox
docker-compose exec php vendor/bin/phpstan analyse
```

Läuft der Stack nicht, vorher `docker-compose up -d`. `vendor/` ist nicht eingecheckt
(`.gitignore`) und muss im Container einmal installiert werden (`composer install`).
Ohne erreichbaren Container ist die einzige ehrliche Antwort, dass die Prüfung nicht
ausgeführt wurde — Ergebnisse nicht schätzen.

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
  ([FRONTEND.md](FRONTEND.md)). Dort gelten PHPStan und PSR-12 nicht, sondern ESLint mit
  `eslint:recommended` + `plugin:vue/vue3-recommended` — **fehlerfrei, halte es so.**
  Ohne lokales Node laufen Lint und Build im Container:

  ```bash
  docker run --rm -v "$PWD/frontend:/app" -w /app node:18-alpine \
    sh -c "npm install && npm run lint"
  docker-compose build frontend      # führt npm run build aus
  ```

- **PHPStan Level 10 und PSR-12 sind erfüllt.** Änderungen müssen das bleiben lassen.
- **Kommentare erklären das Warum.** Der Bestand ist durchgehend so geschrieben; siehe
  AGENTS.md Abschnitt 6. Neuen Code im selben Ton kommentieren, nicht die Signatur nacherzählen.
- **Sprache:** Code und Commit-Messages Englisch, Projektdokumentation Deutsch.
- **Tests, die sich selbst überspringen.** Ohne erreichbare MariaDB meldet die Integration-Suite
  „skipped", nicht „failed". Eine grüne Ausgabe ohne DB beweist nichts über die Persistenz —
  beim Berichten dazusagen.
- **Nicht anfassen:** `vendor/`, `coverage/`, `.phpunit.cache/`, `var/`.
