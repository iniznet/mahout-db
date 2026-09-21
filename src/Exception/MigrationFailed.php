<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * A migration's up() threw. The failure is recorded at critical and fired as
 * mahout/db/migration_failed before it is rethrown in this shape, so every
 * failure has one reason.
 */
final class MigrationFailed extends \RuntimeException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $migration,
        private readonly \Throwable $failure,
    ) {
        parent::__construct($message, 0, $failure);
    }

    public static function duringUp(string $migration, \Throwable $failure): self
    {
        return new self(
            \sprintf('The migration "%s" failed while applying: %s', $migration, $failure::class),
            $migration,
            $failure,
        );
    }

    public static function duringDown(string $migration, \Throwable $failure): self
    {
        return new self(
            \sprintf('The migration "%s" failed while reversing: %s', $migration, $failure::class),
            $migration,
            $failure,
        );
    }

    public function migration(): string
    {
        return $this->migration;
    }

    public function failure(): \Throwable
    {
        return $this->failure;
    }
}
