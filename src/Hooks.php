<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * Every hook mahout-db emits or observes. Names are declared once, here.
 *
 * The two core hooks are constants too: the rule that bans a raw hook name at
 * an emit site applies to a core hook as much as to a mahout one, and these two
 * are the run paths a migration is allowed to travel.
 */
final class Hooks
{
    /**
     * Filters the migrations this site declares.
     *
     * @since 1.0
     *
     * @filter
     *
     * @param list<Migration> $migrations the declared migrations, in order
     */
    public const string MIGRATIONS = 'mahout/db/migrations';

    /**
     * Fires immediately before one migration's up() or down() runs.
     *
     * @since 1.0
     *
     * @action
     *
     * @param string $migration the migration's name
     * @param int    $batch     the batch the run is applying or reversing
     */
    public const string BEFORE_MIGRATE = 'mahout/db/before_migrate';

    /**
     * Fires immediately after one migration ran and its ledger row was written.
     *
     * A reversal fires this too: a dropped index has to invalidate anything
     * that cached its presence.
     *
     * @since 1.0
     *
     * @action
     *
     * @param string $migration the migration's name
     * @param int    $batch     the batch the run applied or reversed
     */
    public const string AFTER_MIGRATE = 'mahout/db/after_migrate';

    /**
     * Fires when a migration throws. The failure is recorded at critical first.
     *
     * @since 1.0
     *
     * @action
     *
     * @param string     $migration the migration's name
     * @param \Throwable $failure   the thrown failure
     */
    public const string MIGRATION_FAILED = 'mahout/db/migration_failed';

    /**
     * Filters the code's schema version, compared to the stored option.
     *
     * @since 1.0
     *
     * @filter
     *
     * @param int $version the code constant
     */
    public const string SCHEMA_VERSION = 'mahout/db/schema_version';

    /**
     * Filters the orphan sources the collection paths operate on.
     *
     * @since 1.0
     *
     * @filter
     *
     * @param list<Contracts\OrphanSource> $sources the declared sources
     */
    public const string ORPHAN_SOURCES = 'mahout/db/orphan_sources';

    /**
     * Fires after a collection path removed orphan rows, with the count.
     *
     * @since 1.0
     *
     * @action
     *
     * @param int $rows the number of rows removed
     */
    public const string ORPHANS_COLLECTED = 'mahout/db/orphans_collected';

    /**
     * Core's post deletion action: the immediate, keyed orphan path.
     *
     * @since 1.0
     *
     * @action
     *
     * @param int $postId the deleted post's id
     */
    public const string DELETED_POST = 'deleted_post';

    /**
     * The orphan sweep's own action. The theme schedules it; this package only
     * attaches the handler, and the handler never runs on a request path.
     *
     * @since 1.0
     *
     * @action
     */
    public const string GC = 'mahout/db/gc';

    /**
     * Core's theme-switch action: the first-install run path.
     *
     * A theme has no activation hook -- register_activation_hook() is
     * plugin-only -- and this action is the theme's equivalent.
     *
     * @since 1.0
     *
     * @action
     */
    public const string AFTER_SWITCH_THEME = 'after_switch_theme';

    /**
     * Core's admin bootstrap action: the lazy run path.
     *
     * @since 1.0
     *
     * @action
     *
     * @param string $hookSuffix the current admin page's hook suffix
     */
    public const string ADMIN_INIT = 'admin_init';

    /**
     * Core's search fragment, applied with the running query.
     *
     * `Search\IndexedSearchSwap` honours it: a query that declared the indexed
     * path gets the FULLTEXT clause, every other query gets its argument back
     * unchanged, and a site with no index keeps running core's LIKE query.
     *
     * @since 1.0
     *
     * @filter
     *
     * @param string    $search the search fragment core built
     * @param \WP_Query $query  the running query
     */
    public const string POSTS_SEARCH = 'posts_search';

    /**
     * Core's search ordering, in the same query as POSTS_SEARCH.
     *
     * The index's own relevance score replaces core's title-match `CASE` for a
     * declared search and for nothing else.
     *
     * @since 1.0
     *
     * @filter
     *
     * @param string    $orderby the ordering core built
     * @param \WP_Query $query   the running query
     */
    public const string POSTS_SEARCH_ORDERBY = 'posts_search_orderby';
}
