# Changelog

All notable changes to this package are recorded here, in Keep a Changelog
order. The format follows Semantic Versioning; a major entry names each removal.

## [Unreleased]

### Added

- The schema objects: `Table`, `Column`, `Index`, `IndexColumn`, `Identifier`,
  `Engine` and `IndexKind`, each enforcing its invariants at declaration time.
- `DdlEmitter`, the one place that writes DDL, with the `CREATE TABLE`,
  `CREATE TABLE IF NOT EXISTS`, `DROP TABLE`, `ALTER TABLE ... ADD`,
  `ALTER TABLE ... DROP INDEX` and `ALTER TABLE ... DROP COLUMN` statements.
- `MigrationLedgerSchema`, the ledger's own declaration.
- The migration runner: `MigrationRunner`, `MigrationList`, `MigrationPlan`,
  `RollbackPlan`, `MigrationRun` and `MigrationStatus`.
- The ledger behind `Contracts` `MigrationStore` and
  `Internal` `WpdbMigrationStore`, and the schema version option behind
  `Contracts` `SchemaVersionStore` and `Internal` `WordPressSchemaVersionStore`.
- `Contracts` `SqlConnection` and `Internal` `WpdbConnection`, the package's one
  boundary to `$wpdb`, with no transaction method.
- The three run paths: `RunPath`, `RunContext`, `Capabilities` and the
  `DbProvider` that attaches `after_switch_theme` and `admin_init`.
- The exit-code contract: `CliExitCode`, with a code of its own for a refusal so
  a script does not retry it.
- The public exception set, every class `final` with a private constructor and
  named constructors carrying typed context.
- The `mahout/db/migrations` and `mahout/db/schema_version` filters, and the
  `mahout/db/before_migrate`, `mahout/db/after_migrate` and
  `mahout/db/migration_failed` actions.
- The architecture-rule proof fixtures for a raw hook name, an unbounded
  statement and a transaction statement outside the gateway, including the
  prefixed (`wptests_howdah_values`) and interpolated
  (`"{$wpdb->prefix}howdah_readthrough"`) forms of a real table name.
- `Contracts` `TableGateway` and `Internal` `WpdbTableGateway`: the typed reads
  and writes, and the one owner of `START TRANSACTION`, `COMMIT` and
  `ROLLBACK`. A nested `transactional()` call joins the open transaction.
- `Row` and `GatewayQuery`: a typed row and a predicate that is bounded by
  construction, with neither a `LIMIT` nor a primary-key equality refused
  (STO-22's runtime floor).
- `SearchIndex`, `AddSearchIndex`, `Contracts` `SearchIndexPresence` and
  `Internal` `OptionSearchIndexPresence`: the `FULLTEXT` index migration on
  core's posts table and its presence cached in one non-autoloaded option.
- `OrphanSweep`, `OrphanCollector`, `SweepRun`, `Contracts` `OrphanSource` and
  `Contracts` `SweepCursor`: the keyed delete on `deleted_post` and the
  chunked, resumable, runtime-capped sweep, with a tombstone surviving both.
- The `mahout/db/orphan_sources` filter, the `mahout/db/orphans_collected`
  action, and the provider's observation of `deleted_post` and
  `mahout/db/gc`.
- New exceptions `UnknownColumn`, `UnboundedStatement`, `InvalidRow` and
  `InvalidOrphanSourceList`.

### Changed

- `DbProvider` declares and resolves its collaborators by contract:
  `SqlConnection`, `MigrationStore` and `SchemaVersionStore` rather than the
  `Internal` classes behind them. This adopts the kernel's contract-keyed
  container, so nothing outside the package has to name an `Internal` class to
  receive one. ADR-0004 is corrected: reading the connection from WordPress is
  unchanged, but the reason it cited is gone.
- The transaction boundary and the search index are this package's, not
  `iniznet/mahout-fields`'s. The 4a package section placed them in a later
  slice; the roadmap's Phase 4 deliverable and the change-routing rule do not.
  Recorded as ADR-0006.
- `MigrationRollbackRefused` says what it means again: "The rollback is refused
  before any statement runs: ... cannot be rolled back." The message had been
  reworded to "reversal"/"cannot be reversed" to dodge an architecture rule that
  matched the bare word `ROLLBACK` anywhere in a literal. The rule now matches
  the keyword in statement position, so prose is prose, and the package's own
  transaction-statement test reads the rule's shared pattern instead of repeating
  it.
