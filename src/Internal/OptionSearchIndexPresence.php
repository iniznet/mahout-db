<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Internal;

use Iniznet\Mahout\Db\Contracts\SearchIndexPresence;
use Iniznet\Mahout\Kernel\RuntimeIdentity;

/**
 * The search index's presence, cached in one non-autoloaded option.
 *
 * present() is an option read and issues no statement, so a request path never
 * performs a schema query. refresh() asks {@see SearchIndexFinder} the live
 * question and rewrites the option; it is called from mahout/db/after_migrate.
 *
 * Presence is a covering index over the declared column list, which is what makes
 * error 1191 impossible rather than unlikely: the option answers the question
 * MATCH() actually asks, and does not care what the index is called. The index
 * itself is one site resource shared by every host, so the option is the only
 * per-host fact about it — each host reads the same schema and records its own
 * answer.
 *
 * @internal
 */
final readonly class OptionSearchIndexPresence implements SearchIndexPresence
{
    private const string OPTION_SUFFIX = 'db_search_index';

    private string $option;

    public function __construct(
        private SearchIndexFinder $finder,
        RuntimeIdentity $identity,
    ) {
        $this->option = $identity->namespacedName(self::OPTION_SUFFIX);
    }

    public function present(): bool
    {
        return (bool) \get_option($this->option, false);
    }

    public function refresh(): bool
    {
        $present = $this->finder->covers();
        \update_option($this->option, $present ? '1' : '0', false);

        return $present;
    }
}
