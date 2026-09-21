<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * The three paths a migration is allowed to travel, and no other.
 *
 * There is no front-end path, no AJAX path and no cron path: a migration on an
 * anonymous request is an unbounded schema change nobody asked for. The two
 * observed paths are attached by DbProvider, at after_switch_theme priority 10
 * and admin_init priority 20.
 */
enum RunPath: string
{
    /** wp mahout migrate. Available when nothing else can run. */
    case Explicit = 'explicit';

    /** after_switch_theme: where a first install creates its tables. */
    case ThemeSwitch = 'theme_switch';

    /** admin_init, when the stored version differs from the code constant. */
    case Lazy = 'lazy';
}
