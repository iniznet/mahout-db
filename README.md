# mahout-db

## What it is

Schema objects, explicit DDL, the migration runner and its ledger. It owns
table declarations, the one place that emits a `CREATE TABLE`, the migration
plan, the run paths a migration is allowed to travel, and the ledger that
records what has run. It owns no field type, no content type and no query
builder.

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

`SqlConnection` deliberately carries no transaction method. The transaction
boundary has exactly one owner, and that owner is the table gateway in
`iniznet/mahout-fields`, never a connection and never a migration.

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
filters the code's version. `mahout/db/before_migrate`,
`mahout/db/after_migrate` and `mahout/db/migration_failed` report a run. The
generated reference is `docs/reference/hooks.md`.

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
`MigrationStore`, `SchemaVersionStore` and `Migration`, and the ledger store
talks to `SqlConnection`.

Every statement the ledger issues is bounded: a primary-key or unique-key
equality, an aggregate over an indexed column, or a `LIMIT 1` existence probe.
The ledger's row count is bounded by the number of registered migrations.

## Licence

GPL-2.0-or-later. See [LICENSE](./LICENSE).
