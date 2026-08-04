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

`make db-migrate` is the same thing. It belongs to a version switch, next to
`composer install` — see [QUICKSTART.md](../../QUICKSTART.md).

Nothing applies migrations on its own. A request must not: four PHP-FPM workers would start
four `ALTER`s on the same table.

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
