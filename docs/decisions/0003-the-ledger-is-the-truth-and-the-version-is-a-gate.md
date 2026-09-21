# ADR-0003 — The ledger is the truth, and the schema version is only a gate

Status: accepted

## Context

Two facts describe what has run: the set of rows in a ledger table, and a
single stored schema version. Either could be the source of truth.

The ledger is exact and can answer "which migration, in which batch, at what
time", but reading it costs a table existence probe and at least one query. A
stored version is one option read, but it cannot say which migration is missing
or which batch to reverse.

Every run path has to answer one question on every request that could trigger
it: is anything pending? Paying a ledger query on an admin request whose schema
is current is a cost with no benefit.

## Decision

- The ledger is the source of truth for what has run. Its declaration is
  `MigrationLedgerSchema`, it is created idempotently by
  `MigrationStore::install()`, and it carries a unique key on the migration name,
  so recording the same migration twice is a database error rather than a silent
  duplicate.
- The stored version is a gate, not a record. `SchemaVersion` compares one code
  constant to one option read; the lazy run path returns before touching the
  ledger when they match. A test asserts the gate performs no ledger read at all.
- The version is written only after the whole batch committed, so a partially
  applied batch never records a version it has not reached.
- `update_option()` returns `false` both on failure and when the value is
  unchanged. Its return is not treated as a failure: the ledger is the truth and
  a write that changes nothing is not an error.
- A schema fact is read once into a non-autoloaded option rather than queried
  per request: the option is stored with `autoload` disabled, because the gate is
  read on the three run paths and never on the front end.

## Consequences

- A request whose schema is current touches no howdah table.
- Deleting the option does not lose information; it makes the next run path
  re-read the ledger and re-derive that nothing is pending.
- Migrations run from an explicit CLI command, from `after_switch_theme`, or
  lazily on `admin_init` for a user who can manage options — never on the front
  end, never under Ajax and never under cron, because an unknown anonymous
  request must not be able to start a schema change.
