<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Internal;

use Iniznet\Mahout\Db\Contracts\SearchIndexPresence;

/**
 * The search index's presence, cached in one non-autoloaded option.
 *
 * present() is an option read and issues no statement, so a request path never
 * performs a schema query. refresh() asks {@see SearchIndexFinder} the live
 * question and rewrites the option; it is called from mahout/db/after_migrate.
 *
 * Presence is a covering index over the declared column list, which is what makes
 * error 1191 impossible rather than unlikely: the option answers the question
 * MATCH() actually asks, and does not care what the index is called.
 *
 * @internal
 */
final readonly class OptionSearchIndexPresence implements SearchIndexPresence
{
    private const string OPTION = 'mahout_db_search_index';

    public function __construct(
        private SearchIndexFinder $finder,
    ) {
    }

    public function present(): bool
    {
        return (bool) \get_option(self::OPTION, false);
    }

    public function refresh(): bool
    {
        $present = $this->finder->covers();
        \update_option(self::OPTION, $present ? '1' : '0', false);

        return $present;
    }
}
