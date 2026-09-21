# mahout-db

## What it is

Schema objects, explicit DDL, the migration runner and its ledger, and the
typed table gateway with the transaction boundary. It owns table declarations,
the one place that emits a `CREATE TABLE`, the migration plan, the run paths a
migration is allowed to travel, the ledger that records what has run, the
search index migration and its cached presence, and orphan collection. It owns
no field type, no content type and no field query builder.

## Installation

There is no Packagist lane. Consume the repository over VCS and pin the major:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/iniznet/mahout-db.git" }
    ],
    "require": {
        "iniznet/mahout-db": "^1.0"
    }
}
```

```bash
composer require iniznet/mahout-db:^1.0
```

A development checkout points at sibling directories through an uncommitted
`composer.dev.json` (path repositories plus `@dev`) and runs
`COMPOSER=composer.dev.json composer install`.

## The public Contracts surface

`src/Contracts/` is the package's public API. Everything under `src/Internal/`
is `@internal` and may change in a patch release.

| Interface | Role | Implementations |
|---|---|---|
| `Migration` | one migration: `name()`, `up()`, `down()`, `irreversibleReason()` | the theme's migration classes |
| `MigrationStore` | the ledger: existence, bootstrap, application order, batch membership, record and forget | `WpdbMigrationStore` |
| `SchemaVersionStore` | the stored schema version: `stored()`, `record()` | `WordPressSchemaVersionStore` |
| `SqlConnection` | the database boundary: `execute()`, `executePrepared()`, `rows()`, `rowsPrepared()`, `prefix()`, `charsetCollate()` | `WpdbConnection` |
| `TableGateway` | the typed read/write boundary and the transaction: `transactional()`, `select()`, `insert()`, `upsert()`, `update()`, `delete()`, `deleteMany()`, `chunk()` | `WpdbTableGateway` |
| `SearchIndexPresence` | whether the search index exists: `present()` (option only), `refresh()` (one schema read) | `OptionSearchIndexPresence` |
| `OrphanSource` | a table whose rows can outlive their posts | the consumer's value object |
| `SweepCursor` | the resumable position of an orphan sweep, by table | `OptionSweepCursor` |

`SqlConnection` deliberately carries no transaction method. The transaction
boundary has exactly one owner, `Contracts\TableGateway`, and its one
implementation is `WpdbTableGateway`, never a connection and never a
migration. The implementation is the only class in the package that issues
`START TRANSACTION`, `COMMIT` or `ROLLBACK`.

## Declaring a table

A table states its columns, its indexes, its engine and its charset/collation.
There is no default for the engine, and the prefix is applied when the schema is
declared, never at query time.

```php
<?php

use Iniznet\Mahout\Db\Column;
use Iniznet\Mahout\Db\Engine;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Index;
use Iniznet\Mahout\Db\IndexColumn;
use Iniznet\Mahout\Db\Table;

$values = new Table(
    name: Identifier::prefixed($wpdb->prefix, 'howdah_field_values'),
    columns: [
        Column::tinyIntUnsigned('object_kind'),
        Column::reference('object_id'),
        Column::varchar('field_id', 191),
        Column::text('value_text')->nullable(),
        Column::decimal('value_dec', 20, 6)->nullable(),
        Column::dateTime('value_date')->nullable(),
    ],
    indexes: [
        Index::primary(
            IndexColumn::of('object_kind'),
            IndexColumn::of('object_id'),
            IndexColumn::of('field_id'),
        ),
        Index::key('field_text', IndexColumn::of('field_id'), IndexColumn::prefixed('value_text', 191)),
        Index::key('field_date', IndexColumn::of('field_id'), IndexColumn::of('value_date')),
    ],
    engine: Engine::InnoDB,
    charsetCollate: $wpdb->get_charset_collate(),
);
```

The declaration is not a query plan and not a migration: it is a value object
that refuses an illegal name, a `varchar` over the 191-byte index cap, a
duplicate column, a second primary key, an index over an undeclared column, and
an auto-increment column no index covers.

## Emitting DDL

`DdlEmitter` is the one place that writes DDL. Core's formatting-sensitive
schema function is never named anywhere in this package, in code or in a test:
it cannot express a drop, and it hides the intent of the statement it emits.

```php
$emitter = new DdlEmitter();

$emitter->create($values);           // CREATE TABLE ... ENGINE=InnoDB ...
$emitter->createIfNotExists($ledger);
$emitter->drop($values->name);
$emitter->addIndex($values->name, $index);
$emitter->dropIndex($values->name, $index);
$emitter->dropColumn($values->name, $column);
```

A table that does not name `InnoDB` is refused before a statement is built
(`EngineNotInnoDB`). The trap is specific: `$wpdb->get_charset_collate()`
supplies the charset and the collation and never the engine, so a `CREATE TABLE`
without an explicit `ENGINE` clause inherits the server's
`default_storage_engine` - and MyISAM ignores a transaction silently.

## Writing a migration

```php
<?php

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Table;

final readonly class CreateValueTable implements Migration
{
    public function __construct(
        private SqlConnection $connection,
        private DdlEmitter $emitter,
        private Table $values,
    ) {
    }

    public function name(): string
    {
        return 'howdah/0001_create_value_table';
    }

    public function up(): void
    {
        $this->connection->execute($this->emitter->create($this->values));
    }

    public function down(): void
    {
        $this->connection->execute($this->emitter->drop($this->values->name));
    }

    public function irreversibleReason(): ?string
    {
        return null;
    }
}
```

Both directions are mandatory: the interface has no default, so a class that
cannot be reversed cannot be registered. A migration that loses information
returns a reason from `irreversibleReason()` **and** throws
`MigrationIrreversible` from `down()`. A test asserts the two agree.

## Registering and running

The composition root names `DbProvider`, adds entries through the
`mahout/db/migrations` filter, and boots the kernel.

```php
<?php

use Iniznet\Mahout\Db\DbProvider;
use Iniznet\Mahout\Db\Hooks;
use Iniznet\Mahout\Kernel\Kernel;

add_filter(Hooks::MIGRATIONS, static function (array $migrations): array {
    $migrations[] = new CreateValueTable($connection, new DdlEmitter(), $values);

    return $migrations;
});

$kernel = Kernel::inWordPress();
$kernel->provider(DbProvider::class);
$kernel->boot();
```

The provider attaches exactly two run paths, and no front-end path exists:

| Path | Hook | Priority |
|---|---|---|
| Explicit | `wp mahout migrate`, bound by the theme's CLI provider | - |
| Theme switch | `after_switch_theme` | 10 |
| Lazy | `admin_init`, only when the stored version differs | 20 |

The lazy path reads one option first and compares it to the code's schema
version, so a request whose schema is current never touches the ledger at all.
It refuses an Ajax request, a cron request and a user who cannot manage options.

## The runner

```php
$runner = $container->get(MigrationRunner::class);

$runner->plan();            // MigrationPlan: pending, applied, next batch. Reads only.
$runner->migrate();         // MigrationRun: the batch and the migrations applied.
$runner->status();          // MigrationStatus: stored version, code version, pending set.
$runner->rollbackPlan(1);   // RollbackPlan: what would be reversed, in order. Reads only.
$runner->rollback(1);       // MigrationRun: the batch and the migrations reversed.
```

Two properties are load-bearing:

1. **A reversal decides everything before it executes anything.**
   `rollback()` builds the whole plan through `rollbackPlan()`, which refuses the
   batch when any migration in it is irreversible - before the first statement
   runs. A batch is therefore never half reversed.
2. **The stored version is written only after the whole batch committed**, so a
   partially applied batch never records a version it has not reached.

## The typed table gateway

Every read and write against a declared table goes through `Contracts\TableGateway`.
Identifiers are never parameters, so the gateway takes the declared `Table` and
a value object keyed by its columns; every quoted identifier is read back from
the schema object.

```php
use Iniznet\Mahout\Db\GatewayQuery;
use Iniznet\Mahout\Db\Row;

$key = Row::of($values, ['object_kind' => 1, 'object_id' => 7, 'field_id' => 'isbn']);

$gateway->transactional(function () use ($gateway, $values, $key): void {
    $gateway->upsert(Row::of($values, [...$key->values(), 'value_text' => '978']));
    $gateway->delete(GatewayQuery::keyed($key));
});
```

`GatewayQuery` has no unbounded form. A keyed query must cover a non-empty
leading prefix of the declared primary key, or a query must declare a `LIMIT`;
anything else throws `UnboundedStatement` before a statement can exist. That is
STO-22's runtime floor, and it is the half an architecture rule cannot see when
a table name reaches a statement as a runtime value.

A nested `transactional()` call joins the open transaction rather than issuing
`START TRANSACTION` again. A failed statement rolls the whole group back,
substitutes nothing and retries nothing.

## The search index and orphan collection

`AddSearchIndex` adds `FULLTEXT KEY howdah_search (post_title, post_excerpt,
post_content)` to the site's posts table and `down()` drops it. Presence is
never queried on a request path: `OptionSearchIndexPresence::refresh()` makes
the one `information_schema.STATISTICS` read and writes a non-autoloaded option,
and `present()` is an option read. The provider refreshes it on
`mahout/db/after_migrate`.

`OrphanCollector` is the keyed delete for a deleted post; `OrphanSweep` is the
chunked, resumable, runtime-capped sweep. Both emit `mahout/db/orphans_collected`.
A consumer registers an `OrphanSource` through the `mahout/db/orphan_sources`
filter, and the theme schedules `mahout/db/gc`.

## Documented public concrete classes

Every documented public class is part of the stable surface within a major.

| Class | Role |
|---|---|
| `DbProvider` | the `ServiceProvider`: the ledger, the version store, the runner and the two run paths |
| `DdlEmitter` | the one place that writes DDL, and the engine check |
| `Table` | a declared table and the invariants it enforces |
| `Column` | one declared column and the exact type the DDL policy fixes |
| `Index` | one declared index, of the primary, unique, key or fulltext kind |
| `IndexColumn` | one index column, with the optional prefix length a `TEXT` column needs |
| `Identifier` | a validated SQL identifier, carrying its prefix |
| `Engine` | `InnoDB`, and the two cases that exist to be refused |
| `IndexKind` | `Primary`, `Unique`, `Key`, `FullText` |
| `MigrationLedgerSchema` | the ledger table declaration |
| `MigrationRunner` | plan, apply, reverse, report |
| `MigrationList` | the registered migrations, in order, with unique names |
| `MigrationPlan`, `RollbackPlan`, `MigrationRun`, `MigrationStatus` | what a command reports |
| `Row`, `GatewayQuery` | a typed row and a bounded predicate for the table gateway |
| `SearchIndex` | the `howdah_search` index declaration and its `ADD`/`DROP` statements |
| `AddSearchIndex` | the migration that adds and drops the search index |
| `OrphanSweep`, `OrphanCollector`, `SweepRun` | the chunked sweep, the immediate keyed delete, and what a sweep did |
| `SchemaVersion` | the code version and the stored version, side by side |
| `RunContext`, `RunPath` | the facts that decide whether a run path may proceed |
| `Capabilities` | the one capability a run path checks |
| `CliExitCode` | the exit codes the WP-CLI contract fixes, including the refusal's own code |
| `Hooks` | every hook constant the package emits or observes |

The exception set under `src/Exception/` is public: every class is `final`,
every constructor is private, and every failure is built through a named
constructor that carries typed context.

## Hooks

`mahout/db/migrations` filters the registered set; `mahout/db/schema_version`
filters the code's version; `mahout/db/orphan_sources` filters the collection
sources. `mahout/db/before_migrate`, `mahout/db/after_migrate` and
`mahout/db/migration_failed` report a run; `mahout/db/orphans_collected`
reports a collection. The provider also observes core's `deleted_post` and the
package's own `mahout/db/gc`. The generated reference is
`docs/reference/hooks.md`.

## Compatibility

| Item | Value |
|---|---|
| PHP | 8.4 or later |
| WordPress | 7.1 or later |
| MySQL / MariaDB | a version whose `InnoDB` supports a 3072-byte index prefix (MySQL 5.7.7+, MariaDB 10.2.2+) |
| `Contracts/` | stable within a major version; a change is a contract change and is published as a major |
| `Internal/` | unguaranteed; may change in a patch release |
| Licence | GPL-2.0-or-later |

## Architecture

`WpdbConnection` is the only class in the package that names `$wpdb`, and
`DbProvider` is the only class that names `WpdbConnection`. Everything above
them is WordPress-blind: the emitter produces a string, the runner talks to
`MigrationStore`, `SchemaVersionStore` and `Migration`, the ledger store and
the table gateway talk to `SqlConnection`, and `WpdbTableGateway` is the one
owner of the transaction statement.

Every statement the ledger issues is bounded: a primary-key or unique-key
equality, an aggregate over an indexed column, or a `LIMIT 1` existence probe.
The ledger's row count is bounded by the number of registered migrations.

## Licence

GPL-2.0-or-later. See [LICENSE](./LICENSE).
