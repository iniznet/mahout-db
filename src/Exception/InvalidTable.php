<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * A table declaration violates an invariant: no columns, a duplicated column or
 * index name, no primary key for an AUTO_INCREMENT column, or more than one
 * primary key.
 */
final class InvalidTable extends \InvalidArgumentException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $table,
    ) {
        parent::__construct($message);
    }

    public static function noColumns(string $table): self
    {
        return new self(\sprintf('The table "%s" declares no columns.', $table), $table);
    }

    public static function duplicateColumn(string $table, string $column): self
    {
        return new self(\sprintf('The table "%s" declares the column "%s" twice.', $table, $column), $table);
    }

    public static function duplicateIndex(string $table, string $index): self
    {
        return new self(\sprintf('The table "%s" declares the index "%s" twice.', $table, $index), $table);
    }

    public static function duplicatePrimaryKey(string $table): self
    {
        return new self(\sprintf('The table "%s" declares more than one primary key.', $table), $table);
    }

    public static function autoIncrementWithoutKey(string $table, string $column): self
    {
        return new self(
            \sprintf('The table "%s" declares "%s" AUTO_INCREMENT but no index covers it.', $table, $column),
            $table,
        );
    }

    public function table(): string
    {
        return $this->table;
    }
}
