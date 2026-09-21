# ADR-0001 — DDL is explicit, and the engine is named per table

Status: accepted

## Context

WordPress conventionally creates a table by assembling a `CREATE TABLE` string
and handing it to core's schema function. That function is a formatter: it
re-parses the statement, it is sensitive to whitespace and to the order of
clauses, it cannot express a drop, and it reports nothing a caller can act on.
It is also not parameterised, so it cannot be driven from a table declaration.

Separately, `$wpdb->get_charset_collate()` returns a clause that names the
charset and the collation and never the engine. A `CREATE TABLE` without an
explicit `ENGINE` clause therefore inherits the server's
`default_storage_engine` — and MyISAM accepts a transaction statement and
ignores it, which turns a later transaction boundary into decoration.

## Decision

- `Table`, `Column`, `Index` and `IndexColumn` are declarations. They validate
  themselves at construction: an identifier outside the SQL grammar, a `varchar`
  or an index prefix over the 191-byte utf8mb4 index cap, a duplicate column, a
  duplicate index name, a second primary key, an index over an undeclared
  column, and an auto-increment column no index covers are all refused before a
  statement exists.
- `DdlEmitter` is the one place that writes DDL. It takes a `Table` and returns
  a string; it holds no connection and executes nothing.
- `Engine` has no default on `Table`, and `create()` refuses anything but
  `InnoDB` with `EngineNotInnoDB` **before** it builds the statement. The
  `MyISAM` and `MEMORY` cases exist so a declaration that asks for one is
  refused rather than silently inherited.
- The prefix is applied when the schema is declared (`Identifier::prefixed`) and
  never at query time, so one object is the single source of truth for a name and
  an identifier never arrives as a caller-supplied string.
- Core's schema function is never named in this repository. A test scans every
  identifier token in `src/`, `tests/` and `fixtures/` to enforce it, reading
  tokens rather than raw text so the docblock that records this decision is not
  itself a violation.

## Consequences

- Every table this package creates is transactional, and `SHOW TABLE STATUS`
  confirms it after the migration rather than trusting the declaration.
- A destructive statement (a column drop) is expressible, which is why an
  irreversible migration can be written at all — and why it must declare itself
  (ADR-0002).
- The emitted DDL is byte-identical on every platform, because statements are
  joined with a newline literal rather than `PHP_EOL`. A golden assertion in the
  test suite is therefore meaningful.
