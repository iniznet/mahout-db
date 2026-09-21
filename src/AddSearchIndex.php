<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;

/**
 * The search index migration: up() adds FULLTEXT KEY howdah_search on the
 * three MATCH columns, down() drops it.
 *
 * ALTER TABLE is DDL and implicitly commits, so it never shares the gateway's
 * transaction. The presence cache is refreshed by the after_migrate listener,
 * not here, per the one-owner rule.
 */
final readonly class AddSearchIndex implements Migration
{
    public function __construct(
        private SqlConnection $connection,
        private DdlEmitter $emitter,
        private SearchIndex $index,
    ) {
    }

    public function name(): string
    {
        return 'mahout/search_index';
    }

    public function up(): void
    {
        $this->connection->execute($this->index->add($this->emitter));
    }

    public function down(): void
    {
        $this->connection->execute($this->index->drop($this->emitter));
    }

    public function irreversibleReason(): ?string
    {
        return null;
    }
}
