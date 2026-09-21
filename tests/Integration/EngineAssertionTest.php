<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Exception\EngineNotInnoDB;
use Iniznet\Mahout\Db\Exception\MigrationFailed;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Db\Tests\Fixtures\FixtureSet;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The engine assertion, from both sides: every table is InnoDB, and a
 * declaration that asks for anything else is refused before the database is
 * touched.
 *
 * @internal
 */
final class EngineAssertionTest extends TestCase
{
    public function testTheConnectionReportsInnoDB(): void
    {
        global $wpdb;

        self::assertSame('InnoDB', $wpdb->get_var('SELECT @@default_storage_engine'));
    }

    public function testEveryTableAMigrationCreatesReportsInnoDB(): void
    {
        $connection = $this->recording();
        $this->runner(
            $this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection),
            $connection,
        )->migrate();

        self::assertSame('InnoDB', $this->tableEngine($this->valueTable()));
        self::assertSame('InnoDB', $this->tableEngine($this->metaTable()));
        self::assertSame('InnoDB', $this->tableEngine($this->ledgerName()));
    }

    public function testANonTransactionalDeclarationFailsTheRunWithoutReachingTheDatabase(): void
    {
        $connection = $this->recording();
        $runner = $this->runner([$this->myisamMigration($connection, $this->notesTable()->declared())], $connection);

        $exception = null;
        try {
            $runner->migrate();
        } catch (MigrationFailed $failure) {
            $exception = $failure;
        }

        self::assertInstanceOf(MigrationFailed::class, $exception);
        self::assertInstanceOf(EngineNotInnoDB::class, $exception->failure());

        // Exactly one statement ran, and it was the ledger bootstrap.
        self::assertCount(1, $connection->writes());
        self::assertStringContainsString($this->ledgerName(), $connection->writes()[0]);

        self::assertSame([], $this->ledgerStore($connection)->applied());
        self::assertSame(0, (int) \get_option(self::LEDGER_OPTION, 0));
        self::assertFalse($this->tableExists($this->prefix().'fixture_myisam'));
    }

    public function testEveryEngineButInnoDBIsRefusedByTheEmitter(): void
    {
        $emitter = new \Iniznet\Mahout\Db\DdlEmitter();
        $table = $this->notesTable()->declared();

        foreach ([\Iniznet\Mahout\Db\Engine::MyISAM, \Iniznet\Mahout\Db\Engine::Memory] as $engine) {
            $declared = new Table(
                name: $table->name,
                columns: $table->columns,
                indexes: $table->indexes,
                engine: $engine,
                charsetCollate: $table->charsetCollate,
            );

            $refused = null;
            try {
                $emitter->create($declared);
            } catch (EngineNotInnoDB $failure) {
                $refused = $failure;
            }

            self::assertInstanceOf(EngineNotInnoDB::class, $refused);
            self::assertSame($engine->value, $refused->engine());
        }
    }

    private function myisamMigration(SqlConnection $connection, Table $declared): Migration
    {
        return new class($connection, $declared) implements Migration {
            public function __construct(
                private readonly SqlConnection $connection,
                private readonly Table $declared,
            ) {
            }

            public function name(): string
            {
                return 'fixture/0005_myisam_table';
            }

            public function up(): void
            {
                $emitter = new \Iniznet\Mahout\Db\DdlEmitter();

                $this->connection->execute($emitter->create(new Table(
                    name: \Iniznet\Mahout\Db\Identifier::prefixed('wptests_', 'fixture_myisam'),
                    columns: $this->declared->columns,
                    indexes: $this->declared->indexes,
                    engine: \Iniznet\Mahout\Db\Engine::MyISAM,
                    charsetCollate: $this->declared->charsetCollate,
                )));
            }

            public function down(): void
            {
            }

            public function irreversibleReason(): ?string
            {
                return null;
            }
        };
    }
}
