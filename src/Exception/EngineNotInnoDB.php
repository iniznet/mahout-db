<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * A table declaration asks for an engine this package does not emit.
 *
 * get_charset_collate() supplies the charset and the collation and never the
 * engine, so a table that does not declare InnoDB silently inherits the
 * server's default_storage_engine. A table field's write is transactional and
 * MyISAM ignores a transaction, so the declaration is refused instead.
 */
final class EngineNotInnoDB extends \DomainException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $table,
        private readonly string $engine,
    ) {
        parent::__construct($message);
    }

    public static function forTable(string $table, string $engine): self
    {
        return new self(
            \sprintf('The table "%s" declares ENGINE=%s; every table this package emits declares ENGINE=InnoDB.', $table, $engine),
            $table,
            $engine,
        );
    }

    public function table(): string
    {
        return $this->table;
    }

    public function engine(): string
    {
        return $this->engine;
    }
}
