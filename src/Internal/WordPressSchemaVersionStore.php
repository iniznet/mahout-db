<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Internal;

use Iniznet\Mahout\Db\Contracts\SchemaVersionStore;

/**
 * The schema version option, stored autoload='no'.
 *
 * update_option() returns false both on failure and when the value is
 * unchanged, so its return is not treated as a failure: the ledger is the
 * source of truth and the option is only a gate. A write that changes nothing
 * is not an error.
 *
 * @internal
 */
final readonly class WordPressSchemaVersionStore implements SchemaVersionStore
{
    private const string OPTION = 'mahout_db_schema_version';

    public function stored(): int
    {
        $stored = \get_option(self::OPTION, 0);

        return \is_numeric($stored) ? (int) $stored : 0;
    }

    public function record(int $version): void
    {
        \update_option(self::OPTION, $version, false);
    }
}
