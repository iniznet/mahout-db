<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * The capabilities mahout-db's run paths check.
 *
 * A capability is a typed constant, never a string literal at the check site: a
 * literal is a typo that silently denies or grants. This enum is the package's
 * own capability surface; the theme declares its own capabilities separately.
 */
enum Capabilities: string
{
    /** A schema change is triggered by a capable user, not by an editor. */
    case ManageOptions = 'manage_options';
}
