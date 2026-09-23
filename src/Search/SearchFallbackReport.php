<?php

/**
 * The one report that a site's search is not running on the index.
 *
 * The fallback to core's LIKE query is defined behaviour, not a degraded mode:
 * the same rows, from the same table, at a measured cost the throughput rules
 * name. What must not happen is silence. The report is therefore loud, and it
 * is made once per request because a line per query would be a cost that grows
 * with traffic -- the one thing a read path may never add.
 */

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Search;

use Iniznet\Mahout\Db\Contracts\SearchIndexPresence;
use Iniznet\Mahout\Kernel\Diagnostics;
use Iniznet\Mahout\Kernel\Level;

final class SearchFallbackReport
{
    private bool $reported = false;

    public function __construct(
        private readonly SearchIndexPresence $presence,
        private readonly Diagnostics $diagnostics,
    ) {
    }

    /** The cached option. No statement, and no schema read. */
    public function isIndexed(): bool
    {
        return $this->presence->present();
    }

    /**
     * Record the absence, once per request.
     *
     * The instance is per request: the provider builds it during registration
     * and the container holds it for the rest of the request, so the flag needs
     * no cache and no global.
     */
    public function reportOnce(): void
    {
        if ($this->reported) {
            return;
        }

        $this->reported = true;

        $this->diagnostics->log(
            level: Level::Error,
            message: 'search index missing; core search path in use',
            context: ['index' => MatchClause::INDEX_NAME],
        );
    }
}
