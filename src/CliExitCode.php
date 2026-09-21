<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Exception\MigrationRollbackRefused;

/**
 * The exit codes the WP-CLI contract fixes for the mahout-db commands.
 *
 * 3 is the package's: a caller must be able to tell a refusal from a failure,
 * because a script that treats a refusal as a failure retries it. The refusal
 * is always decided before the first statement runs.
 *
 * The WP-CLI binding itself lives in the theme's CliProvider: WP_CLI is not a
 * WordPress symbol, and the analyzer's stubs are generated from core alone.
 */
enum CliExitCode: int
{
    case Success = 0;
    case Failure = 1;
    case Usage = 2;
    case Refused = 3;

    /**
     * Map a thrown failure to an exit code. Nothing is swallowed: the caller
     * still holds the exception and prints its reason.
     */
    public static function forFailure(\Throwable $failure): self
    {
        return $failure instanceof MigrationRollbackRefused ? self::Refused : self::Failure;
    }
}
