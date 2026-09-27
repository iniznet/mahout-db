<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Exception\MigrationIrreversible;
use Iniznet\Mahout\Db\Internal\SearchIndexFinder;

/**
 * Adopts a search index this package created under an earlier, themed name.
 *
 * It is its own migration because the sites that need it already carry
 * mahout/search_index in the ledger, and a recorded migration never runs again.
 * The rename is metadata: measured on MariaDB 11.7, RENAME INDEX leaves a
 * FULLTEXT index's column list in place and copies nothing, which is the only
 * reason a site with a large posts table can adopt the derived name at all.
 *
 * Three cases, no fourth. No covering index exists: this migration adds nothing,
 * because adding is AddSearchIndex's job. A covering index carries one of this
 * package's own earlier names: it is renamed. A covering index carries any other
 * name: it is left exactly as its owner named it, because MATCH() already selects
 * it and renaming another system's artefact is not ours to do. The last case is
 * quiet rather than loud because nothing is degraded by it.
 *
 * A host registers this beside AddSearchIndex; the declaration of which schema a
 * site owns stays with the host that owns the site.
 */
final readonly class RenameSearchIndex implements Migration
{
    private readonly SearchIndexFinder $finder;

    /** @see AddSearchIndex for why the finder is built here rather than passed in. */
    public function __construct(
        private SqlConnection $connection,
        private DdlEmitter $emitter,
        private SearchIndex $index,
    ) {
        $this->finder = new SearchIndexFinder($connection, $index);
    }

    public function name(): string
    {
        return 'mahout/rename_search_index';
    }

    public function up(): void
    {
        $found = $this->finder->find();

        if (null === $found || !\in_array($found, SearchIndex::legacyNames(), true)) {
            return;
        }

        $this->connection->execute($this->index->renameFrom($found, $this->emitter));
    }

    public function down(): void
    {
        throw MigrationIrreversible::because($this->name(), $this->irreversibleReason());
    }

    /** @return string this migration never reverses */
    public function irreversibleReason(): string
    {
        return 'the name the index carried before the rename is not recorded anywhere, so a reversal cannot tell a site that had a themed index from one that never did';
    }
}
