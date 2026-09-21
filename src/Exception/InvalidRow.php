<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * A row or a bound is not usable by the gateway: it is empty, it belongs to a
 * different table, it omits part of the primary key, or its limit is not
 * positive.
 */
final class InvalidRow extends \InvalidArgumentException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $table,
    ) {
        parent::__construct($message);
    }

    public static function emptyValues(string $table): self
    {
        return new self(
            \sprintf('An update against "%s" was given no column values to write.', $table),
            $table,
        );
    }

    public static function wrongTable(string $row, string $expected): self
    {
        return new self(
            \sprintf('A row declared for "%s" was used against "%s".', $row, $expected),
            $expected,
        );
    }

    public static function missingPrimaryKey(string $table): self
    {
        return new self(
            \sprintf('A row for "%s" does not carry the full declared primary key.', $table),
            $table,
        );
    }

    public static function nonPositiveLimit(int $limit): self
    {
        return new self(
            \sprintf('A chunk limit of %d is not usable; it must be one or greater.', $limit),
            '',
        );
    }

    public function table(): string
    {
        return $this->table;
    }
}
