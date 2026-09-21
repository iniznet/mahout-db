<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Internal;

use Iniznet\Mahout\Db\Contracts\MigrationStore;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Table;

/**
 * The ledger, persisted in wp_mahout_migrations.
 *
 * Every statement is either a primary-key or unique-key equality, a MAX() over
 * an indexed column, or a LIMIT 1 existence probe. The ledger's row count is
 * bounded by the number of registered migrations, which is small by
 * construction.
 *
 * @internal
 */
final readonly class WpdbMigrationStore implements MigrationStore
{
    public function __construct(
        private SqlConnection $connection,
        private DdlEmitter $emitter,
        private Table $table,
    ) {
    }

    public function exists(): bool
    {
        $rows = $this->connection->rowsPrepared(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
            $this->table->name->value,
        );

        return [] !== $rows;
    }

    public function install(): void
    {
        $this->connection->execute($this->emitter->createIfNotExists($this->table));
    }

    public function latestBatch(): int
    {
        $rows = $this->connection->rows(
            'SELECT COALESCE(MAX(batch), 0) AS latest FROM '.$this->table->name->quoted(),
        );

        $latest = $rows[0]['latest'] ?? null;

        return null === $latest ? 0 : (int) $latest;
    }

    public function applied(): array
    {
        return $this->column(
            'SELECT migration FROM '.$this->table->name->quoted().' ORDER BY id',
            'migration',
        );
    }

    public function namesInBatch(int $batch): array
    {
        return $this->column(
            'SELECT migration FROM '.$this->table->name->quoted().' WHERE batch = %d ORDER BY id',
            'migration',
            $batch,
        );
    }

    public function record(string $migration, int $batch, \DateTimeImmutable $ranAt): void
    {
        $this->connection->executePrepared(
            'INSERT INTO '.$this->table->name->quoted().' (migration, batch, ran_at) VALUES (%s, %d, %s)',
            $migration,
            $batch,
            $ranAt->format('Y-m-d H:i:s'),
        );
    }

    public function forget(string $migration): void
    {
        $this->connection->executePrepared(
            'DELETE FROM '.$this->table->name->quoted().' WHERE migration = %s',
            $migration,
        );
    }

    /**
     * @return list<string>
     */
    private function column(string $statement, string $key, string|int ...$values): array
    {
        $rows = [] === $values
            ? $this->connection->rows($statement)
            : $this->connection->rowsPrepared($statement, ...$values);

        $column = [];
        foreach ($rows as $row) {
            $value = $row[$key] ?? null;
            if (null !== $value) {
                $column[] = $value;
            }
        }

        return $column;
    }
}
