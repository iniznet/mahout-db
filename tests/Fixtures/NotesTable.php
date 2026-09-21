<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Fixtures;

use Iniznet\Mahout\Db\Column;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Engine;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Index;
use Iniznet\Mahout\Db\IndexColumn;
use Iniznet\Mahout\Db\Table;

/**
 * The generic typed value table of the storage contract, declared as a fixture.
 *
 * It is the real shape, not a toy: a composite primary key, a prefixed index on
 * a TEXT column, and nullable value columns. A later slice binds a `Table` field
 * to it.
 *
 * @internal
 */
final readonly class NotesTable
{
    public function __construct(
        private DdlEmitter $emitter,
        private Identifier $name,
        private string $charsetCollate,
    ) {
    }

    public static function nameFor(string $prefix): Identifier
    {
        return Identifier::prefixed($prefix, 'fixture_field_values');
    }

    public function name(): Identifier
    {
        return $this->name;
    }

    public function declared(): Table
    {
        return new Table(
            name: $this->name,
            columns: [
                Column::tinyIntUnsigned('object_kind'),
                Column::reference('object_id'),
                Column::varchar('field_id', 191),
                Column::text('value_text')->nullable(),
                Column::bigInt('value_int')->nullable(),
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
                Index::key('field_int', IndexColumn::of('field_id'), IndexColumn::of('value_int')),
                Index::key('field_dec', IndexColumn::of('field_id'), IndexColumn::of('value_dec')),
                Index::key('field_date', IndexColumn::of('field_id'), IndexColumn::of('value_date')),
            ],
            engine: Engine::InnoDB,
            charsetCollate: $this->charsetCollate,
        );
    }

    public function create(): string
    {
        return $this->emitter->create($this->declared());
    }

    public function drop(): string
    {
        return $this->emitter->drop($this->name);
    }
}
