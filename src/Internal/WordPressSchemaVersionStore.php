<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Internal;

use Iniznet\Mahout\Db\Contracts\SchemaVersionStore;
use Iniznet\Mahout\Db\Exception\InvalidSchemaVersion;
use Iniznet\Mahout\Kernel\RuntimeIdentity;

/**
 * The schema version option, stored autoload='no'.
 *
 * update_option() returns false both on failure and when the value is
 * unchanged, so its return is not treated as a failure: the ledger is the
 * source of truth and the option is only a gate. A write that changes nothing
 * is not an error.
 *
 * The option is named for the host that owns it. Two mahout systems on one site
 * run at their own schema version, and a shared gate would have one host's
 * migration suppress the other's.
 *
 * @internal
 */
final readonly class WordPressSchemaVersionStore implements SchemaVersionStore
{
    private const string OPTION_SUFFIX = 'db_schema_version';

    private string $option;

    public function __construct(RuntimeIdentity $identity)
    {
        $this->option = $identity->namespacedName(self::OPTION_SUFFIX);
    }

    public function stored(): int
    {
        $stored = \get_option($this->option, 0);

        // A stored value that is not a number is a corrupt option: reading
        // it as zero would silently re-enter the lazy migrate path.
        if (!\is_numeric($stored)) {
            throw InvalidSchemaVersion::corruptStoredOption(\get_debug_type($stored));
        }

        return (int) $stored;
    }

    public function record(int $version): void
    {
        \update_option($this->option, $version, false);
    }
}
