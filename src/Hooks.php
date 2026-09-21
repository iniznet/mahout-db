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
}
