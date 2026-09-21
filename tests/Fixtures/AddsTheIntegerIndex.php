<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Fixtures;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Index;
use Iniznet\Mahout\Db\IndexColumn;

/**
 * An index-only migration: the shape of the indexed search path, whose up() adds
 * a key and whose down() drops exactly that key.
 *
 * @internal
 */
final readonly class AddsTheIntegerIndex implements Migration
{
    public function __construct(
        private SqlConnection $connection,
        private DdlEmitter $emitter,
        private Identifier $table,
    ) {
    }

    public function name(): string
    {
        return 'fixture/0003_add_object_index';
    }

    public function up(): void
    {
        $this->connection->execute($this->emitter->addIndex($this->table, $this->declared()));
    }

    public function down(): void
    {
        $this->connection->execute($this->emitter->dropIndex($this->table, $this->declared()));
    }

    public function irreversibleReason(): ?string
    {
        return null;
    }

    private function declared(): Index
    {
        return Index::key('field_object', IndexColumn::of('object_kind'), IndexColumn::of('object_id'));
    }
}
