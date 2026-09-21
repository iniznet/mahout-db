<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * The ledger records a migration whose class is no longer registered, so its
 * down() cannot be reached. A rollback refuses rather than dropping the row.
 */
final class MigrationMissing extends \RuntimeException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $migration,
    ) {
        parent::__construct($message);
    }

    public static function fromLedger(string $migration): self
    {
        return new self(
            \sprintf('The ledger records the migration "%s", which is no longer registered.', $migration),
            $migration,
        );
    }

    public function migration(): string
    {
        return $this->migration;
    }
}
