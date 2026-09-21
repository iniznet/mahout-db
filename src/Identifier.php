<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Exception\InvalidIdentifier;

/**
 * A validated SQL identifier.
 *
 * The table prefix is applied here, when the schema is declared
 * (Identifier::prefixed), and never at query time, so one object is the single
 * source of truth for a name. An identifier cannot be parameterised, so it never
 * arrives as a caller-supplied string at query time either: the gateway reads it
 * from the schema object, per the security contract.
 */
final readonly class Identifier
{
    private const int MAX_LENGTH = 64;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (\strlen($value) > self::MAX_LENGTH) {
            throw InvalidIdentifier::tooLong($value);
        }

        if (1 !== \preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw InvalidIdentifier::forValue($value);
        }

        return new self($value);
    }

    /**
     * Compose a prefixed table name at declaration time. The prefix is a
     * parameter of the declaration, not of the query.
     */
    public static function prefixed(string $prefix, string $suffix): self
    {
        return self::fromString($prefix.$suffix);
    }

    public function quoted(): string
    {
        return '`'.$this->value.'`';
    }
}
