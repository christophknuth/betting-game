# Migrations

`schema.sql` is the schema of the current version, and Docker reads it **only into an empty
data directory**. On a stack that is already running it therefore changes nothing: the
tables stay as they were, and the first request that selects a new column answers `500`.

That is what this directory is for. Every schema change is also a file here, applied in the
order of its number and written down in `schema_migration` once it has run.

## Applying them

```bash
docker-compose exec php php bin/migrate --status   # what is pending
docker-compose exec php php bin/migrate            # apply it
```

`make db-migrate` and `composer migrate` are the same thing. It belongs to a version switch,
next to `composer install` — see [QUICKSTART.md](../../QUICKSTART.md).

### The container does it too

Since the entrypoint took it on, starting the application container applies what is pending
first. That makes a deployment one step: the server comes up against a schema it fits, or it
does not come up. Set `MIGRATE_ON_START=0` where the migration is to be a job of its own.

**A request still must not** — four PHP-FPM workers would start four `ALTER`s on the same
table. The entrypoint runs once, before the server forks, so those workers do not exist yet.
Two *containers* starting together still could, and `Migrator::migrate()` therefore takes a
named lock on the database: the second one waits for the first and then finds nothing
pending. Waiting rather than refusing is the point — a second container is a normal
deployment, not a fault.

`bin/migrate --wait=SECONDS` sits out a database that is still coming up. Only the entrypoint
uses it; by hand, an unreachable database is an answer rather than something to wait through.

A migration that fails takes the container down with it. That is deliberate: an application
whose schema is older than its code answers `500` on the pages that need the new column, and
it does so at a moment nobody is watching.

**Not a Composer hook.** `post-install-cmd` would fire while the image is being built, where
there is no database to reach and a non-zero exit breaks the build; keeping it out of the
build with `--no-scripts` would leave it firing only on a developer's machine, which is the
one place it is not needed.

## Writing one

```
database/migrations/0005_short_description.sql
```

The number decides the order and is what gets recorded, so it must not change afterwards —
renaming the descriptive half is free.

Four rules:

1. **The same change goes into `schema.sql`**, which stays the schema of a fresh
   installation. The migration is that change as a step; `schema.sql` is the result.
2. **Running it twice must change nothing.** MariaDB has `ADD COLUMN IF NOT EXISTS`,
   `ADD INDEX IF NOT EXISTS`, `DROP COLUMN IF EXISTS`; where a statement reads an old column
   that a fresh database never had, assemble it with `PREPARE` (see `0003`). DDL commits
   implicitly, so a migration that fails halfway cannot be rolled back — being able to run
   it again is what takes the place of a transaction.
3. **End every statement with `;` at the end of a line.** That is where the runner splits.
4. **A fresh database runs them too**, where they find everything in place and do nothing
   but write their line. This is deliberate: "has this database been migrated?" then has one
   answer, however the database came about.

## What they are not

They do not touch `event_store`. The event log is immutable — a field an older event was
written without is read as nullable in the code, never filled in by an `UPDATE` here. Read
models are different: they can always be thrown away and rebuilt
(`POST /admin/projections/{name}/rebuild`).
