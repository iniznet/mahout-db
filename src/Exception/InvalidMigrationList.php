<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * The mahout/db/migrations filter returned something that is not a list of
 * Migration objects.
 */
final class InvalidMigrationList extends \UnexpectedValueException implements MahoutException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function notAList(string $hook): self
    {
        return new self(\sprintf('The %s filter must return a list.', $hook));
    }

    public static function notAMigration(string $hook): self
    {
        return new self(\sprintf('The %s filter returned a value that is not a Migration.', $hook));
    }
}
