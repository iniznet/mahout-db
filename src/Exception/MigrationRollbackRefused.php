<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * A rollback run was refused because at least one migration in the batch is
 * irreversible. The refusal is decided before the first statement executes, so
 * a batch is never half rolled back.
 */
final class MigrationRollbackRefused extends \DomainException implements MahoutException
{
    /**
     * @param list<string> $migrations
     */
    private function __construct(
        string $message,
        private readonly array $migrations,
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<string> $migrations
     */
    public static function forMigrations(array $migrations): self
    {
        return new self(
            \sprintf(
                'The rollback is refused before any statement runs: %s cannot be rolled back.',
                \implode(', ', $migrations),
            ),
            $migrations,
        );
    }

    /** @return list<string> */
    public function migrations(): array
    {
        return $this->migrations;
    }
}
