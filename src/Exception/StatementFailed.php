<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * The database refused a statement. No statement is retried and no partial
 * result is returned.
 */
final class StatementFailed extends \RuntimeException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $statement,
        private readonly string $error,
    ) {
        parent::__construct($message);
    }

    public static function forStatement(string $statement, string $error): self
    {
        return new self(
            \sprintf('The database refused a statement: %s', '' === $error ? 'no error text was reported' : $error),
            $statement,
            $error,
        );
    }

    public static function unreadableResult(string $statement): self
    {
        return new self(
            'The result set of a prepared statement could not be read; the connection is not ready.',
            $statement,
            '',
        );
    }

    public function statement(): string
    {
        return $this->statement;
    }

    public function error(): string
    {
        return $this->error;
    }
}
