<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Internal\SearchIndexFinder;

/**
 * The search index migration: up() adds the FULLTEXT index over the three MATCH
 * columns, down() drops it.
 *
 * up() is conditional because the index is a site resource, not a package
 * resource. A covering index already present -- created by this package under an
 * earlier name, or by anything else -- serves MATCH() as it stands, and adding a
 * second index over identical columns of a shared core table doubles the write
 * cost of every post save and isolates nothing.
 *
 * ALTER TABLE is DDL and implicitly commits, so it never shares the gateway's
 * transaction. The presence cache is refreshed by the after_migrate listener,
 * not here, per the one-owner rule.
 */
final readonly class AddSearchIndex implements Migration
{
    private readonly SearchIndexFinder $finder;

    /**
     * The finder is built from the two values a host already passes here, so a
     * host registers this migration with the same three arguments it always did.
     * It resolves no collaborator: it is the question those two objects can
     * already answer, given a name of its own.
     */
    public function __construct(
        private SqlConnection $connection,
        private DdlEmitter $emitter,
        private SearchIndex $index,
    ) {
        $this->finder = new SearchIndexFinder($connection, $index);
    }

    public function name(): string
    {
        return 'mahout/search_index';
    }

    public function up(): void
    {
        if ($this->finder->covers()) {
            return;
        }

        $this->connection->execute($this->index->add($this->emitter));
    }

    /**
     * Drops only the index this migration names. An index over the same columns
     * that another owner named is not this migration's to remove, and dropping it
     * would take a running site's search away with it.
     */
    public function down(): void
    {
        if ($this->index->name() !== $this->finder->find()) {
            return;
        }

        $this->connection->execute($this->index->drop($this->emitter));
    }

    public function irreversibleReason(): ?string
    {
        return null;
    }
}
