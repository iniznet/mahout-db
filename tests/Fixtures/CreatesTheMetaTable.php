<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Fixtures;

use Iniznet\Mahout\Db\Column;
use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Engine;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Index;
use Iniznet\Mahout\Db\IndexColumn;
use Iniznet\Mahout\Db\Table;

/**
 * A second table, so a batch carries more than one migration and a reversal has
 * an order to walk backwards.
 *
 * @internal
 */
final readonly class CreatesTheMetaTable implements Migration
{
    public function __construct(
        private SqlConnection $connection,
        private DdlEmitter $emitter,
        private Identifier $name,
        private string $charsetCollate,
    ) {
    }

    public function name(): string
    {
        return 'fixture/0002_create_meta_table';
    }

    public function up(): void
    {
        $this->connection->execute($this->emitter->create($this->declared()));
    }

    public function down(): void
    {
        $this->connection->execute($this->emitter->drop($this->name));
    }

    public function irreversibleReason(): ?string
    {
        return null;
    }

    private function declared(): Table
    {
        return new Table(
            name: $this->name,
            columns: [
                Column::identifier('id'),
                Column::varchar('note_key', 191),
                Column::dateTime('ran_at'),
            ],
            indexes: [
                Index::primary(IndexColumn::of('id')),
                Index::unique('note_key', IndexColumn::of('note_key')),
            ],
            engine: Engine::InnoDB,
            charsetCollate: $this->charsetCollate,
        );
    }
}
