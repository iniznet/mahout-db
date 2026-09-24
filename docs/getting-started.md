# Getting started

## Install

```bash
composer require iniznet/mahout-db:^1.0
```

Requires PHP 8.4 and WordPress 7.1 or later, and depends on
`iniznet/mahout-kernel`.

## Declare and create your first table

A table is a value object that refuses illegal names, indexes over undeclared
columns and a non-InnoDB engine — before any statement exists. `DdlEmitter`
is the one place that writes DDL:

```php
use Iniznet\Mahout\Db\Column;
use Iniznet\Mahout\Db\DdlEmitter;
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
    ],
    indexes: [
        Index::primary(
            IndexColumn::of('object_kind'),
            IndexColumn::of('object_id'),
            IndexColumn::of('field_id'),
        ),
    ],
    engine: Engine::InnoDB,
    charsetCollate: $wpdb->get_charset_collate(),
);

(new DdlEmitter())->create($values);
```

The engine is explicit because `$wpdb->get_charset_collate()` supplies the
charset and never the engine — and MyISAM ignores a transaction silently.

## Read and write through the gateway

`TableGateway` is the typed boundary and the transaction's one owner:

```php
$rows = $gateway->select($values->name, ['field_id' => 'subtitle']);

$newHash = $gateway->transactional(function () use ($gateway, $values): string {
    $gateway->upsert($values->name, ['field_id' => 'subtitle', /* … */]);

    return $gateway->select($mirror, ['group_id' => $groupId])[0]['hash'];
});
```

Every statement is bounded — a `LIMIT` or a primary-key equality, always. A
`SqlConnection` deliberately carries no transaction method; the boundary has
exactly one owner.

## Migrations run from explicit paths

A migration implements `Migration` (`name()`, `up()`, `down()`,
`irreversibleReason()`). Migrations run from `wp mahout migrate`, from
`after_switch_theme`, or lazily on `admin_init` for a `manage_options`
user — never on the front end, never under AJAX or cron. An irreversible
`down()` throws and blocks the whole rollback run before any statement
executes.

## Failure modes

| Symptom | Cause |
|---|---|
| `EngineNotInnoDB` | a table declared without `Engine::InnoDB` |
| A declaration throws on construction | an illegal name, a `varchar` over the 191-byte index cap, a duplicate column, a second primary key, an index over an undeclared column, or an auto-increment no index covers |
| A rollback refuses to run | a migration's `down()` declared itself irreversible |
