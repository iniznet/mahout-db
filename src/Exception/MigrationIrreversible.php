<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * A migration's down() cannot restore the prior state from the new shape alone.
 *
 * down() throws this before executing anything, and the reason is also declared
 * through Migration::irreversibleReason() so --rollback can refuse the whole
 * batch before the first statement runs.
 */
final class MigrationIrreversible extends \LogicException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $migration,
        private readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function because(string $migration, string $reason): self
    {
        return new self(
            \sprintf('The migration "%s" cannot be reversed: %s', $migration, $reason),
            $migration,
            $reason,
        );
    }

    public function migration(): string
    {
        return $this->migration;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
