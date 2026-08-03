# CLAUDE.md

Notes for Claude Code in this repository.

## Read this first

**[AGENTS.md](AGENTS.md) holds the complete project guide** — domain, architecture,
commands, conventions, pitfalls. This file only adds what comes on top for Claude Code in
this working environment. Where the two contradict, AGENTS.md wins.

## In brief

A PHP 8.4 API for a **Lotto 6 aus 49 syndicate**. No framework, onion architecture,
event sourcing + CQRS, MariaDB, Keycloak (OIDC). Expansion stage **base**: participants
only read, the administrator writes everything.

## Running analyses and tests

PHP, Composer and PHPUnit do not have to be installed locally — they run in the container.

**Tests belong in the dedicated test environment**, not in the `php` container: that one's
`DB_DATABASE` is the development database, and the integration suite truncates every table
before each test. `IntegrationTestCase` now refuses anything that does not end in `_test` —
so inside the `php` container all 173 integration tests skip themselves.

```bash
docker-compose -f docker-compose.test.yml up -d test-db
docker-compose -f docker-compose.test.yml run --rm test                    # phpunit
docker-compose -f docker-compose.test.yml run --rm test vendor/bin/phpstan analyse
docker-compose -f docker-compose.test.yml down -v
```

Static analysis on its own also runs in the dev container (`docker-compose exec php
vendor/bin/phpstan analyse`) — that one writes nothing.

If the stack is not running, `docker-compose up -d` first. `vendor/` is not checked in
(`.gitignore`) and has to be installed once inside the container (`composer install`).
Without a reachable container the only honest answer is that the check was not run —
do not estimate results.

The frontend has its own suites (Vitest, Playwright) — see [FRONTEND.md](FRONTEND.md).

## Key files to start from

| Question | File |
|---|---|
| What does the application do, in domain terms? | [USER_STORIES.md](USER_STORIES.md) |
| Which endpoints exist? | [src/Presentation/Router/Router.php](src/Presentation/Router/Router.php), [betting_game_api.yaml](betting_game_api.yaml) |
| How does a request flow? | [src/Presentation/Http/Kernel.php](src/Presentation/Http/Kernel.php) |
| How is everything wired up? | [src/Infrastructure/DI/Container.php](src/Infrastructure/DI/Container.php) |
| Which tables exist? | [database/schema.sql](database/schema.sql) |
| How does a schema change reach a database that has data? | [database/migrations/README.md](database/migrations/README.md), `bin/migrate` |

## Things to keep in mind

- **State of the docs.** The documentation has been caught up since 2026-07-29 — the table
  in [AGENTS.md](AGENTS.md) section 2 says what holds.
- **The frontend has no PHP.** `frontend/` is a Vue 3 SPA against the lotto endpoints
  ([FRONTEND.md](FRONTEND.md)). PHPStan and PSR-12 do not apply there; ESLint does, with
  `eslint:recommended` + `plugin:vue/vue3-recommended` — **clean, keep it that way.**
  Without a local Node, lint and build run in the container:

  ```bash
  docker run --rm -v "$PWD/frontend:/app" -w /app node:24-alpine \
    sh -c "npm install && npm run lint"
  docker-compose build frontend      # runs npm run build
  ```

- **PHPStan level 10 and PSR-12 are met.** Changes have to leave it that way.
- **Comments explain the why.** The existing code is written that way throughout; see
  AGENTS.md section 6. Comment new code in the same tone, do not restate the signature.
- **Language:** everything that lands in the repository is written in English — code,
  comments, documentation and commit messages. The one exception is the frontend's
  user-facing text (labels, messages, `de-DE` date and currency formatting), which stays
  German because that is the language of the syndicate using it.
- **Tests that skip themselves.** Without a reachable MariaDB the integration suite reports
  "skipped", not "failed". Green output without a database proves nothing about persistence —
  say so when reporting.
- **Do not touch:** `vendor/`, `coverage/`, `.phpunit.cache/`, `var/`.
