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
 * A table whose rows can outlive the posts they reference. Its primary key
 * leads with the object reference, exactly as the generic value table does, so
 * a keyed delete on the object is a primary-key prefix equality.
 *
 * @internal
 */
final readonly class OrphanFixtureTable
{
    public function __construct(
        private DdlEmitter $emitter,
        private Identifier $name,
        private string $charsetCollate,
    ) {
    }

    public static function nameFor(string $prefix): Identifier
    {
        return Identifier::prefixed($prefix, 'fixture_orphans');
    }

    public function declared(): Table
    {
        return new Table(
            name: $this->name,
            columns: [
                Column::reference('post_id'),
                Column::intUnsigned('seq'),
                Column::tinyIntUnsigned('tombstoned'),
                Column::text('payload')->nullable(),
            ],
            indexes: [
                Index::primary(IndexColumn::of('post_id'), IndexColumn::of('seq')),
                Index::key('newest', IndexColumn::of('post_id'), IndexColumn::of('seq')),
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
