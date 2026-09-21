<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * A statement against a howdah table carries neither a LIMIT nor a
 * primary-key equality, or a key that is not a leading prefix of the declared
 * primary key was mistaken for one. STO-22.
 *
 * The architecture rule is a compile-time floor and is silent on a table name
 * that reaches a statement as a runtime value. This refusal is the runtime
 * floor that covers exactly that case.
 */
final class UnboundedStatement extends \RuntimeException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $table,
    ) {
        parent::__construct($message);
    }

    public static function forTable(string $table): self
    {
        return new self(
            \sprintf('A statement against "%s" carries neither a LIMIT nor a primary-key equality.', $table),
            $table,
        );
    }

    public static function forLimit(string $table, int $limit): self
    {
        return new self(
            \sprintf('A LIMIT of %d against "%s" is not a bound; it must be one or greater.', $limit, $table),
            $table,
        );
    }

    public function table(): string
    {
        return $this->table;
    }
}
