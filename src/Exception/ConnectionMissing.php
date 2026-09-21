<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * The composition root asked for the WordPress connection and there is none.
 * The request stops; no connection object is fabricated.
 */
final class ConnectionMissing extends \RuntimeException implements MahoutException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function withoutWpdb(): self
    {
        return new self('There is no $wpdb connection in scope.');
    }
}
