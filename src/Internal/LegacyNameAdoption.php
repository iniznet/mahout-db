<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Internal;

use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\MigrationLedgerSchema;
use Iniznet\Mahout\Kernel\RuntimeIdentity;

/**
 * Moves an installed site's unsuffixed schema under the host's identity.
 *
 * This is not a migration, and it cannot be one. The ledger is where migrations
 * are recorded, so a migration that renamed the ledger would be read from the new
 * name -- which is empty -- and would conclude that nothing had ever run. The
 * package already keeps one statement outside the ledger for the same kind of
 * reason (DdlEmitter::createIfNotExists()), and this is the second: it runs as the
 * first act of a migration run, before the ledger is read, and on no other path.
 *
 * It is a rename rather than a copy because the history is the whole value of a
 * ledger: a fresh empty ledger would make every migration pending again, and one
 * of them flattens repeater rows in chunks. RENAME TABLE copies no rows, and
 * options move through their own API so the object cache stays coherent with what
 * the database now says.
 *
 * Both moves are guarded by the presence of the target: a site that already
 * carries the new name keeps it, and a leftover unsuffixed option is removed
 * rather than kept, because after this release nothing reads it, and a stale fact
 * about the schema is worse than no fact.
 *
 * @internal
 */
final readonly class LegacyNameAdoption
{
    /**
     * The option names this package wrote before a host declared an identity,
     * mapped to the suffix the identity is composed with.
     *
     * @var array<string, string>
     */
    private const array LEGACY_OPTIONS = [
        'mahout_db_schema_version' => 'db_schema_version',
        'mahout_db_search_index' => 'db_search_index',
        'mahout_db_sweep_cursors' => 'db_sweep_cursors',
    ];

    /** The ledger's name before any host declared an identity. */
    private const string LEGACY_LEDGER = 'mahout_migrations';

    public function __construct(
        private SqlConnection $connection,
        private DdlEmitter $emitter,
        private RuntimeIdentity $identity,
    ) {
    }

    public function adopt(): void
    {
        $this->adoptLedger();

        foreach (self::LEGACY_OPTIONS as $legacy => $suffix) {
            $this->adoptOption($legacy, $this->identity->namespacedName($suffix));
        }
    }

    private function adoptLedger(): void
    {
        $legacy = Identifier::prefixed($this->connection->prefix(), self::LEGACY_LEDGER);
        $current = MigrationLedgerSchema::nameFor($this->connection->prefix(), $this->identity);

        if (!$this->tableExists($legacy->value) || $this->tableExists($current->value)) {
            return;
        }

        $this->connection->execute($this->emitter->renameTable($legacy, $current));
    }

    private function tableExists(string $name): bool
    {
        $rows = $this->connection->rowsPrepared(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
            $name,
        );

        return [] !== $rows;
    }

    private function adoptOption(string $legacy, string $current): void
    {
        // get_option() cannot distinguish a stored false from an absent option, so
        // an identity object is the sentinel: no option value is ever this.
        $absent = new \stdClass();
        $value = \get_option($legacy, $absent);

        if ($absent === $value) {
            return;
        }

        if ($absent !== \get_option($current, $absent)) {
            \delete_option($legacy);

            return;
        }

        \add_option($current, $value, '', false);
        \delete_option($legacy);
    }
}
