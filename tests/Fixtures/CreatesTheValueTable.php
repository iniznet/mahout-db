<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Fixtures;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;

/**
 * Applies the fixture value table. Its down() drops exactly what up() created,
 * so up() then down() has to return the schema to its prior state.
 *
 * @internal
 */
final readonly class CreatesTheValueTable implements Migration
{
    public function __construct(
        private SqlConnection $connection,
        private NotesTable $table,
    ) {
    }

    public function name(): string
    {
        return 'fixture/0001_create_value_table';
    }

    public function up(): void
    {
        $this->connection->execute($this->table->create());
    }

    public function down(): void
    {
        $this->connection->execute($this->table->drop());
    }

    public function irreversibleReason(): ?string
    {
        return null;
    }
}
