<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * An index declaration violates an invariant: no columns, or a column the
 * table does not declare.
 */
final class InvalidIndex extends \InvalidArgumentException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $index,
        private readonly string $column = '',
    ) {
        parent::__construct($message);
    }

    public static function noColumns(string $index): self
    {
        return new self(\sprintf('The index "%s" declares no columns.', $index), $index);
    }

    public static function prefixLength(string $column, int $length): self
    {
        return new self(
            \sprintf('The column "%s" declares an unusable index prefix length of %d.', $column, $length),
            '',
            $column,
        );
    }

    public static function unknownColumn(string $index, string $column): self
    {
        return new self(
            \sprintf('The index "%s" names the column "%s", which the table does not declare.', $index, $column),
            $index,
            $column,
        );
    }

    public function index(): string
    {
        return $this->index;
    }

    public function column(): string
    {
        return $this->column;
    }
}
