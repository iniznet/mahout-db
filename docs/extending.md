# Extending

## A new migration

One class per migration, constructed with the collaborators it names — the
connection, the emitter — and registered with the runner the consumer drives:

1. Implement `Contracts\Migration`: `name()`, `up()`, `down()`,
   `irreversibleReason()`.
2. `up()` emits DDL through `DdlEmitter` — the one place DDL exists — and
   never names core's formatting-sensitive schema function.
3. `down()` either reverses every statement or throws from
   `irreversibleReason()`; a refusal blocks the whole rollback run before any
   statement executes.
4. Test the round trip on both targets: the migration contract is proved on
   the real gateway, not on a mock.

## An orphan sweep

Implement `Contracts\OrphanSource` for a table whose rows can outlive their
posts. The sweep is chunked by key, resumable through `Contracts\SweepCursor`,
runtime-capped, and never runs on a request path. The suite proves a constant
statement count per chunk and a strictly advancing cursor.

## Search without owning the grammar

The FULLTEXT path — the tokeniser, the `MATCH` clause, the two core filters
and the loud fallback report — is this package's
(`Search\\SearchTerms`, `Search\\IndexedSearchSwap`,
`Search\\SearchProvider`). A consumer states the search intent and takes
the query args from the swap; it declares no search grammar of its own. A
malformed search term never reaches `AGAINST`.

## What a migration may never do

Read or write through any boundary but the injected `SqlConnection` and
`DdlEmitter`; open its own transaction (the gateway owns the boundary); run
on a request path; or store state outside the ledger.
