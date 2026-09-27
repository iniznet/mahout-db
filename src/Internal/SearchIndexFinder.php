<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Internal;

use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\SearchIndex;

/**
 * Which FULLTEXT index on core's posts table covers the declared columns.
 *
 * MATCH() selects an index by its column list and ignores its name, so the
 * question a site has to answer is "does an index over exactly these columns
 * exist", not "does the index this package would have created exist". Asking it
 * by name was the defect: a site that held a covering index under any other name
 * was told it had none, and a second consumer was told to create a second index
 * over columns the first already covered.
 *
 * One owner of that question, read live from the schema. Presence caches it in an
 * option ({@see OptionSearchIndexPresence}); this asks it, and is called from the
 * migration path only, never a request path.
 *
 * @internal
 */
final readonly class SearchIndexFinder
{
    public function __construct(
        private SqlConnection $connection,
        private SearchIndex $index,
    ) {
    }

    /**
     * The name of a FULLTEXT index whose columns are exactly the declared list,
     * or null when the table holds no such index.
     *
     * One statement, bounded to the table and the index type; a posts table
     * carries no other FULLTEXT index in the schema this package declares.
     */
    public function find(): ?string
    {
        $rows = $this->connection->rowsPrepared(
            'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_TYPE = %s'
            .' ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            $this->index->table()->value,
            'FULLTEXT',
        );

        /** @var array<string, list<string>> $candidates */
        $candidates = [];

        foreach ($rows as $row) {
            $name = $row['INDEX_NAME'] ?? null;
            $column = $row['COLUMN_NAME'] ?? null;

            if (null === $name || null === $column) {
                continue;
            }

            /** @var list<string> $columns */
            $columns = $candidates[(string) $name] ?? [];
            $columns[] = (string) $column;
            $candidates[(string) $name] = $columns;
        }

        foreach ($candidates as $name => $columns) {
            if ($columns === $this->index->columns()) {
                return $name;
            }
        }

        return null;
    }

    public function covers(): bool
    {
        return null !== $this->find();
    }
}
