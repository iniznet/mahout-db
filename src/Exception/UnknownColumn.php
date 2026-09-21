<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * A value object named a column the declared table does not carry.
 *
 * Identifiers come from the schema object alone. A caller never supplies a
 * column name that reaches SQL; a key that names an undeclared column is
 * rejected here, at the value object's boundary.
 */
final class UnknownColumn extends \InvalidArgumentException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $table,
        private readonly string $column,
    ) {
        parent::__construct($message);
    }

    public static function inTable(string $table, string $column): self
    {
        return new self(
            \sprintf('The table "%s" declares no column "%s".', $table, $column),
            $table,
            $column,
        );
    }

    public static function inRow(string $table, string $column): self
    {
        return new self(
            \sprintf('The row for "%s" carries no value for the column "%s".', $table, $column),
            $table,
            $column,
        );
    }

    public function table(): string
    {
        return $this->table;
    }

    public function column(): string
    {
        return $this->column;
    }
}
