# ADR-0006 — The gateway and orphan collection live here

Status: accepted. Corrects the package section written during slice 4a, which
placed the transaction gateway in `iniznet/mahout-fields` and the search-index
migration in a later slice.

## Context

Slice 4a created schema objects, the explicit DDL emitter, the migration runner
and its ledger. Its package section recorded two scope boundaries that the
roadmap does not support:

1. `SqlConnection` carries no transaction method "because the transaction
   boundary belongs to the table gateway in `iniznet/mahout-fields`".
2. The search index is "declared here but not created by this package".

The delivery roadmap's Phase 4 deliverable is "Schema objects, the migration
runner, and the typed table gateway **with the transaction boundary**", and the
change-routing rule in `19` §3 routes "a table, an index, a migration, or the
transaction gateway" to `iniznet/mahout-db`. `mahout-fields` depends on
`mahout-db` precisely because the gateway is not its to own (REP-03).

## Decision

- `Contracts\TableGateway` and its `Internal\WpdbTableGateway` implementation
  are this package's. The implementation is the one owner of `START TRANSACTION`,
  `COMMIT` and `ROLLBACK`, and the only class the devtools transaction rule
  exempts.
- `SqlConnection` keeps `execute()`, `executePrepared()`, `rows()`,
  `rowsPrepared()`, `prefix()` and `charsetCollate()` and gains no transaction
  method. The boundary is the gateway's; the connection stays a statement
  boundary.
- `SearchIndex`, `AddSearchIndex`, `Contracts\SearchIndexPresence` and
  `Internal\OptionSearchIndexPresence` are this package's. The presence option is
  refreshed from `mahout/db/after_migrate`, which the provider attaches.
- `OrphanSweep`, `OrphanCollector`, `Contracts\OrphanSource` and
  `Contracts\SweepCursor` are this package's. The provider attaches
  `deleted_post` (the keyed path) and `mahout/db/gc` (the sweep); the theme
  schedules `mahout/db/gc`.

## Consequences

- The package's `README.md`, `AGENTS.md` and public `Contracts` table are
  updated in the same change. Nothing committed links into the private
  planning corpus.
- `mahout-fields` consumes `Contracts\TableGateway` and `Contracts\OrphanSource`;
  it does not implement them.
- The theme owns the `mahout/db/gc` schedule and the WP-CLI binding. The
  package owns the handlers and the exit-code contract (`CliExitCode`).

## Rejected alternatives

| Alternative | Why not |
|---|---|
| Keep the boundary in `mahout-fields` | It contradicts the Phase 4 deliverable and the change-routing rule, and it would make `mahout-db`'s typed gateway a read/write builder with no atomicity |
| Add a transaction method to `SqlConnection` | Two owners for one boundary. The devtools rule exempts the gateway, not the connection |
| Put the search index migration in the theme | `19` §3 routes "a migration" to `mahout-db`; the theme registers the migration, the package writes it |
