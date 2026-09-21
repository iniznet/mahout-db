<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Exception\InvalidColumn;

/**
 * One declared column, with the SQL type the DDL policy fixes for it.
 *
 * Every named constructor produces the exact type text of the policy:
 * bigint(20) unsigned for an id or a reference, varchar(191) when indexed, a
 * datetime in UTC. A varchar is capped at 191 because utf8mb4's index cap is
 * 767 bytes and every varchar the policy permits is indexed.
 */
final readonly class Column
{
    private const int MAX_VARCHAR = 191;

    private function __construct(
        public Identifier $name,
        public string $type,
        public bool $nullable,
        public bool $autoIncrement,
    ) {
    }

    /** An AUTO_INCREMENT primary-key column. */
    public static function identifier(string $name): self
    {
        return new self(Identifier::fromString($name), 'bigint(20) unsigned', false, true);
    }

    /** A reference to another entity's id. */
    public static function reference(string $name): self
    {
        return new self(Identifier::fromString($name), 'bigint(20) unsigned', false, false);
    }

    public static function bigInt(string $name): self
    {
        return new self(Identifier::fromString($name), 'bigint(20)', false, false);
    }

    public static function intUnsigned(string $name): self
    {
        return new self(Identifier::fromString($name), 'int(10) unsigned', false, false);
    }

    public static function tinyIntUnsigned(string $name): self
    {
        return new self(Identifier::fromString($name), 'tinyint(3) unsigned', false, false);
    }

    public static function varchar(string $name, int $length): self
    {
        if ($length < 1 || $length > self::MAX_VARCHAR) {
            throw InvalidColumn::varcharLength($name, $length);
        }

        return new self(Identifier::fromString($name), 'varchar('.$length.')', false, false);
    }

    public static function text(string $name): self
    {
        return new self(Identifier::fromString($name), 'text', false, false);
    }

    public static function decimal(string $name, int $precision, int $scale): self
    {
        if ($precision < 1 || $precision > 65 || $scale < 0 || $scale > 30 || $scale > $precision) {
            throw InvalidColumn::decimalShape($name, $precision, $scale);
        }

        return new self(Identifier::fromString($name), 'decimal('.$precision.', '.$scale.')', false, false);
    }

    /** A timestamp in UTC. One convention; never a unix integer. */
    public static function dateTime(string $name): self
    {
        return new self(Identifier::fromString($name), 'datetime', false, false);
    }

    public function nullable(): self
    {
        if ($this->autoIncrement) {
            throw InvalidColumn::autoIncrementCannotBeNullable($this->name->value);
        }

        return new self($this->name, $this->type, true, false);
    }

    public function definition(): string
    {
        return $this->name->quoted().' '.$this->type
            .($this->nullable ? ' NULL' : ' NOT NULL')
            .($this->autoIncrement ? ' AUTO_INCREMENT' : '');
    }
}
