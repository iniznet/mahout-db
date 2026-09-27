# mahout-db

## What it is

Schema objects, explicit DDL, the migration runner and its ledger, and the
typed table gateway with the transaction boundary. It owns table declarations,
the one place that emits a `CREATE TABLE`, the migration plan, the run paths a
migration is allowed to travel, the ledger that records what has run, the
search index migration and its cached presence, the FULLTEXT search path (the
tokeniser, the `MATCH` clause and the two core filters that swap them into a
query), and orphan collection. It owns
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
| `StatementPreparer` | a statement rendered with every value bound, and not executed: `prepare()` | `WpdbConnection` |
| `OrphanSource` | a table whose rows can outlive their posts | the consumer's value object |
| `SweepCursor` | the resumable position of an orphan sweep, by table | `OptionSweepCursor` |

`SqlConnection` deliberately carries no transaction method. The transaction
boundary has exactly one owner, `Contracts\TableGateway`, and its one
implementation is `WpdbTableGateway`, never a connection and never a
migration. The implementation is the only class in the package that issues
`START TRANSACTION`, `COMMIT` or `ROLLBACK`.

`StatementPreparer` exists because a search fragment is not this package's to
run: core's `WP_Query` interpolates it into its own `WHERE`. The one
implementation is the same `WpdbConnection` object that implements
`SqlConnection`, so a value that travels as text has still been through a
placeholder, and no second class anywhere names `$wpdb`.

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

## The search index, the indexed query and orphan collection

`AddSearchIndex` adds `FULLTEXT KEY mahout_posts_search (post_title, post_excerpt,
post_content)` to the site's posts table and `down()` drops it; the package
contributes both it and `RenameSearchIndex` without being asked, because a host
has no reason to request an index it was never told about. `RenameSearchIndex`
adopts the name this package used to give the index, `howdah_search`, taken from
one starter's identity; it renames a covering index that carries one of this
package's own earlier names and leaves any other name exactly as its owner named
it.

Presence is never queried on a request path, and it is answered by the column
list rather than the name, because that is what `MATCH()` selects by:
`Internal\SearchIndexFinder` asks the one `information_schema.STATISTICS` read,
`OptionSearchIndexPresence::refresh()` writes a non-autoloaded option, and
`present()` is an option read. The provider refreshes it on
`mahout/db/after_migrate`.

### The indexed search path

`Search\SearchTerms` is the tokeniser: a visitor-supplied string never reaches
`AGAINST` raw. Only runs of word characters survive — no boolean operator,
quote, wildcard or punctuation — at most `MAX_TOKENS` of them, each at most
`MAX_TOKEN_BYTES` long, and none shorter than the index's `MIN_TOKEN_SIZE`. A
term with no usable token leaves `hasTokens()` false so the consumer renders its
empty state instead of running a scan.

`Search\MatchClause` renders `MATCH (…) AGAINST (%s IN NATURAL LANGUAGE MODE)`.
Its column list is read from `SearchIndex::onPosts()`, never retyped, because
`MATCH` must name the index's columns exactly or the server raises error 1191,
and its value travels through `Contracts\StatementPreparer`. The clause is built
for one table: `table()` and `columns()` are the same declaration the presence
check reads, so the two can never disagree. `fragment()` returns the payload
core's `posts_search` filter expects — including core's own `AND` and
parenthesisation, which are core's grammar and are written once, here — and
`relevance()` returns the ordering `posts_search_orderby` expects.

`Search\IndexedSearchSwap` is what a consumer actually uses. `args()` turns a
tokenised term into the query args, and `search()` / `orderby()` are the two
core filters that honour a query's declaration:

```php
$swap = $container->get(IndexedSearchSwap::class);

$query = new WP_Query($myArgs + $swap->args(SearchTerms::fromString($term)));
```

When the index is present the args carry three package-owned query vars —
`mahout_indexed_search`, `mahout_match_clause`, `mahout_match_orderby` — and the
two filters replace core's LIKE fragment and its title-match ordering. When it is
absent the args are `['s' => $term]`, core's own query with no flag set, and the
absence is recorded once per request through `Search\SearchFallbackReport` —
loud, but never one line per query. A term with no usable token is refused on
both paths rather than sent to a leading-wildcard scan. A declaration whose
payload is missing, empty or not a string throws
`Exception\InvalidSearchDeclaration` rather than swapping in an empty fragment,
which for `posts_search` would match every published post.

`Search\SearchProvider` builds the clause, the report and the swap, publishes the
swap under its own class name, and attaches the two filters. It registers after
`DbProvider`, which is where `SearchIndexPresence` and `Diagnostics` come from:

```php
$kernel = Kernel::inWordPress();
$kernel->provider(DbProvider::class);
$kernel->provider(SearchProvider::class);
$kernel->boot();
```

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
| `SearchIndex` | the `mahout_posts_search` index declaration, its `ADD`/`DROP`/`RENAME` statements, and the column list the clause and the presence check both read |
| `AddSearchIndex` | the migration that adds and drops the search index, and creates no second index over columns an existing one already covers |
| `RenameSearchIndex` | the migration that adopts the name this package used to give the index; it reverses by refusal, because the name it replaced is not recorded |
| `Search\SearchTerms` | the tokeniser: the word runs, the caps, and the empty state a term with no token produces |
| `Search\MatchClause` | the `MATCH` expression, its `posts_search` fragment and its relevance ordering, built from the index declaration |
| `Search\IndexedSearchSwap` | the query args a search carries and the two core filters that honour the declaration |
| `Search\SearchFallbackReport` | the one loud report that a site's search is not running on the index |
| `Search\SearchProvider` | the `ServiceProvider` that builds the swap and attaches the two filters |
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
package's own `mahout/db/gc`, and `SearchProvider` observes core's
`posts_search` and `posts_search_orderby`. The generated reference is
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

`WpdbConnection` is the only class in the package that names `$wpdb`. It is the
only class that renders a statement, too: it implements both `SqlConnection`,
the boundary that runs one, and `StatementPreparer`, the boundary that renders
one for another party's query without running it. `DbProvider` and
`MatchClause::fromWordPress()` are the only places that name
`WpdbConnection`.
Everything above them is WordPress-blind: the emitter produces a string, the
runner talks to `MigrationStore`, `SchemaVersionStore` and `Migration`, the
ledger store and the table gateway talk to `SqlConnection`, the clause talks to
`StatementPreparer`, and `WpdbTableGateway` is the one owner of the transaction
statement.

Every statement the ledger issues is bounded: a primary-key or unique-key
equality, an aggregate over an indexed column, or a `LIMIT 1` existence probe.
The ledger's row count is bounded by the number of registered migrations.

## Licence

GPL-2.0-or-later. See [LICENSE](./LICENSE).
