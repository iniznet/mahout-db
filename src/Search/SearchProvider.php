<?php

/**
 * The composition-root entry point for the indexed search path.
 *
 * It declares the clause, the fallback report and the swap, and it attaches
 * core's two search filters to the swap's own callbacks -- a hook is attached
 * by a provider and nowhere else.
 *
 * `mahout-db` must register before this provider, because the cached index
 * presence is resolved from the container under `Contracts\SearchIndexPresence`
 * rather than built a second time here. That ordering is already the shape of
 * the theme's composition root, where `DbProvider` is registered first.
 *
 * The swap is inert until a query declares the indexed path through
 * {@see IndexedSearchSwap::args()}, so attaching the two filters costs every
 * other query one boolean check, and a site whose index has not been built
 * keeps running core's query.
 */

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Search;

use Iniznet\Mahout\Db\Contracts\SearchIndexPresence;
use Iniznet\Mahout\Db\Hooks;
use Iniznet\Mahout\Kernel\Container;
use Iniznet\Mahout\Kernel\Contracts\ServiceProvider;
use Iniznet\Mahout\Kernel\Diagnostics;

final class SearchProvider implements ServiceProvider
{
    private const int FILTER_PRIORITY = 10;

    public function register(Container $container): void
    {
        $clause = MatchClause::fromWordPress();

        $container->set($clause);
        $container->set(new IndexedSearchSwap(
            clause: $clause,
            fallback: new SearchFallbackReport(
                presence: $container->get(SearchIndexPresence::class),
                diagnostics: $container->get(Diagnostics::class),
            ),
        ));
    }

    public function boot(Container $container): void
    {
        $swap = $container->get(IndexedSearchSwap::class);

        \add_filter(
            Hooks::POSTS_SEARCH,
            $swap->search(...),
            priority: self::FILTER_PRIORITY,
            accepted_args: 2,
        );

        \add_filter(
            Hooks::POSTS_SEARCH_ORDERBY,
            $swap->orderby(...),
            priority: self::FILTER_PRIORITY,
            accepted_args: 2,
        );
    }
}
