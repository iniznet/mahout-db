<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * The code's schema version and the stored one, side by side.
 *
 * The gate is one option read compared to one constant. It is not a ledger
 * query: a request whose schema is current must not touch the database at all.
 */
final readonly class SchemaVersion
{
    public function __construct(
        public int $code,
        public int $stored,
    ) {
    }

    public function pending(): bool
    {
        return $this->code !== $this->stored;
    }
}
