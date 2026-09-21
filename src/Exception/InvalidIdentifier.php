<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * A declared table, column or index name is not a legal SQL identifier.
 *
 * Identifiers are never parameterisable, so they are validated at declaration
 * time and never accepted as a caller-supplied string at query time.
 */
final class InvalidIdentifier extends \InvalidArgumentException implements MahoutException
{
    private const int MAX_LENGTH = 64;

    private function __construct(
        string $message,
        private readonly string $identifier,
    ) {
        parent::__construct($message);
    }

    public static function forValue(string $identifier): self
    {
        return new self(
            \sprintf('The identifier "%s" is not a legal SQL identifier; letters, digits and underscores only, and it must not start with a digit.', $identifier),
            $identifier,
        );
    }

    public static function tooLong(string $identifier): self
    {
        return new self(
            \sprintf('The identifier "%s" exceeds %d characters.', $identifier, self::MAX_LENGTH),
            $identifier,
        );
    }

    public function identifier(): string
    {
        return $this->identifier;
    }
}
