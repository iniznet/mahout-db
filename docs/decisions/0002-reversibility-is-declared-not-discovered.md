# ADR-0002 — Reversibility is declared, not discovered

Status: accepted

## Context

A rollback of several migrations can fail in the middle: once a batch is half
reversed, the schema is in a state no migration describes. The obvious
implementation — call `down()` on each migration in turn and stop at the first
throw — cannot avoid that, because by the time `down()` throws the earlier
reversals have already run.

## Decision

- `Migration` has four members, not three: `name()`, `up()`, `down()` and
  `irreversibleReason(): ?string`. A migration that loses information returns a
  reason and throws `MigrationIrreversible` from `down()`. The two must agree,
  and a test asserts that they do for every fixture.
- `rollback()` calls `rollbackPlan()` first. That method is read-only: it walks
  the ledger, resolves each recorded name against the registered set, collects
  the irreversible ones, and throws `MigrationRollbackRefused` naming every one
  of them **before any statement runs**. A batch is therefore never half
  reversed, and the refusal is a single value a caller can act on.
- `MigrationMissing` is thrown when the ledger records a migration the code no
  longer registers. It is a failure, not a refusal: the code cannot know what
  the missing migration would have done.
- `CliExitCode` gives a refusal its own exit code (3), so a script that treats a
  failure as retryable does not retry a refusal it will always get.

## Consequences

- `rollbackPlan()` is exactly what `--rollback --dry-run` prints, so the dry run
  and the real run cannot disagree: they are the same computation.
- A migration author has one extra method to write, and no way to hide an
  irreversible change behind a `down()` that quietly does nothing.
