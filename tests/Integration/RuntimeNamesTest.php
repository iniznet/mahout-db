<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Internal\LegacyNameAdoption;
use Iniznet\Mahout\Db\Internal\WordPressSchemaVersionStore;
use Iniznet\Mahout\Db\Tests\Fixtures\FixtureSet;
use Iniznet\Mahout\Db\Tests\TestCase;
use Iniznet\Mahout\Kernel\Container;
use Iniznet\Mahout\Kernel\Exception\RuntimeIdentityNotDeclared;
use Iniznet\Mahout\Kernel\RuntimeIdentity;

/**
 * The names the db package writes into a site, and the one-time move of an
 * installed site's unsuffixed schema under a declared identity.
 *
 * Two things are proven here. That two hosts on one site are two systems: each
 * reads and writes its own option and its own ledger, so neither can suppress the
 * other's migrations. And that upgrading a site that already has history keeps
 * that history: the ledger is renamed, not rebuilt, because an empty ledger would
 * make every migration pending again -- including the one that flattens repeater
 * rows in chunks.
 *
 * @internal
 */
final class RuntimeNamesTest extends TestCase
{
    public function testAProviderWithoutADeclaredIdentityRefusesToRegister(): void
    {
        $container = new Container();
        $container->set($this->diagnostics());

        try {
            (new \Iniznet\Mahout\Db\DbProvider())->register($container);
            self::fail('a host that declared no identity must be refused, not given a default one');
        } catch (RuntimeIdentityNotDeclared $refusal) {
            self::assertStringContainsString('declared no runtime identity', $refusal->getMessage());
            self::assertStringContainsString('fromSlug', $refusal->remedy());
        }
    }

    public function testTwoIdentitiesComposeTwoOfEveryName(): void
    {
        $other = RuntimeIdentity::fromSlug('other');

        self::assertSame('wp_mahout_suite_migrations', self::identity()->tableName('wp_', 'migrations'));
        self::assertSame('wp_mahout_other_migrations', $other->tableName('wp_', 'migrations'));
        self::assertNotSame(self::ledgerOption(), $other->namespacedName('db_schema_version'));
        self::assertSame('mahout_other_db_schema_version', $other->namespacedName('db_schema_version'));
    }

    /**
     * The suppression this prevents: one host's recorded schema version gating a
     * second host out of its own migration.
     */
    public function testEachHostRecordsItsOwnSchemaVersion(): void
    {
        $mine = new WordPressSchemaVersionStore(self::identity());
        $theirs = new WordPressSchemaVersionStore(RuntimeIdentity::fromSlug('other'));

        self::assertSame(0, $theirs->stored());

        $mine->record(4);

        self::assertSame(4, $mine->stored());
        self::assertSame(0, $theirs->stored(), 'one host\'s version must not gate another out of migrating');
    }

    public function testAnInstalledSitesUnsuffixedSchemaMovesUnderItsIdentity(): void
    {
        $connection = $this->connection();
        $this->dropAllLedgers();
        $legacy = Identifier::prefixed($this->prefix(), 'mahout_migrations');
        $current = $this->ledgerName();

        // Built by renaming the declared table, so the test never holds a literal
        // table name inside a statement, and the two tables are the same shape by
        // construction rather than by copy.
        $emitter = new DdlEmitter();
        $connection->execute($emitter->create($this->ledger()));
        $connection->execute($this->recordMigration('mahout/fields/value_table'));
        $connection->execute($emitter->renameTable(Identifier::fromString($current), $legacy));

        \update_option('mahout_db_schema_version', '3');

        self::assertFalse($this->tableExists($current));
        self::assertTrue($this->tableExists($legacy->value));

        (new LegacyNameAdoption($connection, $emitter, self::identity()))->adopt();

        self::assertTrue($this->tableExists($current));
        self::assertFalse($this->tableExists($legacy->value));
        self::assertSame(
            ['mahout/fields/value_table'],
            $this->ledgerStore($connection)->applied(),
            'the history travels with the table; an empty ledger would make everything pending again',
        );
        self::assertSame('3', (string) \get_option(self::ledgerOption(), ''));
        self::assertSame(false, \get_option('mahout_db_schema_version', false));
    }

    /**
     * A site that already carries the new name keeps it: the unsuffixed ledger is
     * left standing rather than renamed over it, because RENAME TABLE would fail
     * on an existing target and a failed migration run blocks every other site.
     */
    public function testAdoptionNeverRenamesOntoAnExistingTable(): void
    {
        $connection = $this->connection();
        $emitter = new DdlEmitter();
        $legacy = Identifier::prefixed($this->prefix(), 'mahout_migrations');

        $connection->execute($emitter->create($this->ledger()));
        $connection->execute($emitter->create(
            new \Iniznet\Mahout\Db\Table(
                name: $legacy,
                columns: $this->ledger()->columns,
                indexes: $this->ledger()->indexes,
                engine: $this->ledger()->engine,
                charsetCollate: $this->charsetCollate(),
            ),
        ));

        (new LegacyNameAdoption($connection, $emitter, self::identity()))->adopt();

        self::assertTrue($this->tableExists($legacy->value), 'the unsuffixed ledger is not this run\'s to remove');
        self::assertTrue($this->tableExists($this->ledgerName()));
    }

    public function testAdoptionIsANoOpWhereNothingWasEverInstalled(): void
    {
        $connection = $this->connection();
        $this->dropAllLedgers();

        (new LegacyNameAdoption($connection, new DdlEmitter(), self::identity()))->adopt();

        self::assertFalse($this->tableExists($this->ledgerName()));
        self::assertSame(false, \get_option(self::ledgerOption(), false));
    }

    public function testAdoptionRunsInsideTheMigrationRunAndBeforeTheLedgerIsRead(): void
    {
        $connection = $this->connection();
        $emitter = new DdlEmitter();
        $legacy = Identifier::prefixed($this->prefix(), 'mahout_migrations');

        $connection->execute($emitter->create($this->ledger()));
        $connection->execute($this->recordMigration('mahout/already/applied'));
        $connection->execute($emitter->renameTable(Identifier::fromString($this->ledgerName()), $legacy));

        $run = $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection)->migrate();

        self::assertSame(['fixture/0001_create_value_table'], $run->migrations);
        self::assertSame(
            ['mahout/already/applied', 'fixture/0001_create_value_table'],
            $this->ledgerStore($connection)->applied(),
            'the adopted history must be read, not replayed',
        );
        self::assertFalse($this->tableExists($legacy->value));
    }

    /**
     * DDL implicitly commits, so the per-test transaction cannot undo a table this
     * suite created with a raw statement: both the declared ledger and the
     * unsuffixed one a migration run adopts are removed here, and no test depends
     * on the order it ran in.
     */
    private function dropAllLedgers(): void
    {
        $emitter = new DdlEmitter();

        foreach ([$this->ledgerName(), Identifier::prefixed($this->prefix(), 'mahout_migrations')->value] as $name) {
            $this->connection()->execute($emitter->drop(Identifier::fromString($name)));
        }
    }

    private function recordMigration(string $name): string
    {
        global $wpdb;

        return 'INSERT INTO '.$this->ledger()->name->quoted()
            .' (migration, batch, ran_at) VALUES ('.$wpdb->prepare('%s', $name).', 1, NOW());';
    }
}
