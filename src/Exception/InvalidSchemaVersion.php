<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * The mahout/db/schema_version filter returned something that is not an
 * integer. The gate compares it to a stored option read, so a non-integer would
 * make the comparison meaningless.
 */
final class InvalidSchemaVersion extends \UnexpectedValueException implements MahoutException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function notAnInteger(string $hook): self
    {
        return new self(\sprintf('The %s filter must return an integer.', $hook));
    }

    public static function corruptStoredOption(string $type): self
    {
        return new self(\sprintf('The stored schema version is a %s; the ledger gate needs an integer, and a corrupt value is refused, never read as zero.', $type));
    }
}
