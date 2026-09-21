<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * A column declaration violates an invariant of the DDL policy.
 */
final class InvalidColumn extends \InvalidArgumentException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $column,
    ) {
        parent::__construct($message);
    }

    public static function varcharLength(string $column, int $length): self
    {
        return new self(
            \sprintf('The column "%s" declares an unusable varchar length of %d.', $column, $length),
            $column,
        );
    }

    public static function decimalShape(string $column, int $precision, int $scale): self
    {
        return new self(
            \sprintf('The column "%s" declares an unusable decimal(%d, %d).', $column, $precision, $scale),
            $column,
        );
    }

    public static function autoIncrementCannotBeNullable(string $column): self
    {
        return new self(
            \sprintf('The column "%s" is AUTO_INCREMENT and therefore cannot be NULL.', $column),
            $column,
        );
    }

    public function column(): string
    {
        return $this->column;
    }
}
