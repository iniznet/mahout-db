<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Internal;

use Iniznet\Mahout\Db\Contracts\SearchIndexPresence;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\SearchIndex;

/**
 * The search index's presence, cached in one non-autoloaded option.
 *
 * present() is an option read and issues no statement, so a request path never
 * performs a schema query. refresh() performs the single information_schema
 * read and rewrites the option; it is called from mahout/db/after_migrate.
 *
 * Presence is true only when the reported column list equals the declared list
 * exactly, which is what makes error 1191 impossible rather than unlikely.
 *
 * @internal
 */
final readonly class OptionSearchIndexPresence implements SearchIndexPresence
{
    private const string OPTION = 'mahout_db_search_index';

    public function __construct(
        private SqlConnection $connection,
        private SearchIndex $index,
    ) {
    }

    public function present(): bool
    {
        return (bool) \get_option(self::OPTION, false);
    }

    public function refresh(): bool
    {
        $rows = $this->connection->rowsPrepared(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND INDEX_TYPE = %s'
            .' ORDER BY SEQ_IN_INDEX',
            $this->index->table()->value,
            $this->index->index()->name->value,
            'FULLTEXT',
        );

        $columns = [];
        foreach ($rows as $row) {
            $name = $row['COLUMN_NAME'] ?? null;
            if (null !== $name) {
                $columns[] = $name;
            }
        }

        $present = $columns === $this->index->columns();
        \update_option(self::OPTION, $present ? '1' : '0', false);

        return $present;
    }
}
