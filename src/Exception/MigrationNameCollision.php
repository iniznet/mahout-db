<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * Two registered migrations declare the same name. The ledger's UNIQUE key
 * would refuse the second, and a name is the only identity a migration has.
 */
final class MigrationNameCollision extends \LogicException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $migration,
    ) {
        parent::__construct($message);
    }

    public static function forName(string $migration): self
    {
        return new self(
            \sprintf('Two migrations declare the name "%s"; a migration name is unique.', $migration),
            $migration,
        );
    }

    public function migration(): string
    {
        return $this->migration;
    }
}
