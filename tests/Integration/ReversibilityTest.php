<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\Exception\MigrationIrreversible;
use Iniznet\Mahout\Db\Tests\Fixtures\FixtureSet;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * Reversibility: up() then down() has to return the schema to the state it was
 * in before, and a migration that cannot do that has to say so.
 *
 * @internal
 */
final class ReversibilityTest extends TestCase
{
    public function testATableReversesToAbsent(): void
    {
        $connection = $this->recording();
        $runner = $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection);
        $runner->migrate();

        self::assertTrue($this->tableExists($this->valueTable()));

        $run = $runner->rollback(1);

        self::assertSame(['fixture/0001_create_value_table'], $run->migrations);
        self::assertFalse($this->tableExists($this->valueTable()));
        self::assertSame([], $this->ledgerStore($connection)->applied());
        self::assertTrue($this->ledgerStore($connection)->exists());
    }

    public function testEveryReversedTableIsGoneAndTheLedgerIsEmpty(): void
    {
        $connection = $this->recording();
        $runner = $this->runner(
            $this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection),
            $connection,
        );
        $runner->migrate();

        $runner->rollback(1);

        self::assertFalse($this->tableExists($this->valueTable()));
        self::assertFalse($this->tableExists($this->metaTable()));
        self::assertSame([], $this->ledgerStore($connection)->applied());
        self::assertSame(0, $this->ledgerStore($connection)->latestBatch());
    }

    /**
     * The index-only shape, which is the one that matters for the search path:
     * down() drops exactly the key up() added and touches nothing else.
     */
    public function testAnIndexOnlyMigrationReversesExactlyItsOwnIndex(): void
    {
        $connection = $this->recording();
        $table = $this->valueTable();

        $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection)->migrate();
        $before = $this->indexNames($table);
        self::assertNotContains('field_object', $before);

        $adding = $this->runner(
            $this->fixtures([FixtureSet::VALUE, FixtureSet::OBJECT_INDEX], $connection),
            $connection,
        );
        $adding->migrate();

        self::assertContains('field_object', $this->indexNames($table));

        $run = $adding->rollback(1);

        self::assertSame(['fixture/0003_add_object_index'], $run->migrations);
        self::assertSame(2, $run->batch);
        self::assertSame($before, $this->indexNames($table));
        self::assertTrue($this->tableExists($table), 'the table itself is untouched');
    }

    public function testEveryDeclaredReversibilityReasonAgreesWithWhatDownDoes(): void
    {
        $connection = $this->connection();
        $migrations = $this->fixtures(
            [FixtureSet::VALUE, FixtureSet::META, FixtureSet::OBJECT_INDEX, FixtureSet::DROP_DECIMAL],
            $connection,
        );
        $this->runner($migrations, $connection)->migrate();

        // Reverse declaration order, because a later down() depends on an
        // earlier table still existing.
        foreach (\array_reverse($migrations) as $migration) {
            $reason = $migration->irreversibleReason();

            try {
                $migration->down();
            } catch (MigrationIrreversible $refusal) {
                self::assertNotNull($reason, $migration->name().' threw a refusal it never declared');
                self::assertSame($reason, $refusal->reason(), $migration->name());

                continue;
            }

            self::assertNull($reason, $migration->name().' declared a refusal its down() did not enforce');
        }
    }

    public function testTheIrreversibleFixtureDeclaresItsReasonInWords(): void
    {
        $migrations = $this->fixtures([FixtureSet::DROP_DECIMAL], $this->connection());

        $reason = $migrations[0]->irreversibleReason();

        self::assertIsString($reason);
        self::assertNotSame('', $reason);
        self::assertStringContainsString('cannot be reconstructed', $reason);
    }
}
