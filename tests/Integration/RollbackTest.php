<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\Exception\MigrationMissing;
use Iniznet\Mahout\Db\Exception\MigrationRollbackRefused;
use Iniznet\Mahout\Db\Tests\Fixtures\FixtureSet;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * Reversal: which batch, in which order, and what is refused before anything
 * runs.
 *
 * @internal
 */
final class RollbackTest extends TestCase
{
    public function testARollbackReversesTheLastBatchAndLeavesTheEarlierOnesAlone(): void
    {
        $connection = $this->recording();

        $this->runner($this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection), $connection)->migrate();
        $this->runner(
            $this->fixtures([FixtureSet::VALUE, FixtureSet::META, FixtureSet::OBJECT_INDEX], $connection),
            $connection,
        )->migrate();

        $run = $this->runner(
            $this->fixtures([FixtureSet::VALUE, FixtureSet::META, FixtureSet::OBJECT_INDEX], $connection),
            $connection,
        )->rollback(1);

        self::assertSame(2, $run->batch);
        self::assertSame(['fixture/0003_add_object_index'], $run->migrations);
        self::assertSame(['fixture/0001_create_value_table', 'fixture/0002_create_meta_table'], $this->ledgerStore($connection)->applied());
        self::assertTrue($this->tableExists($this->metaTable()));
        self::assertNotContains('field_object', $this->indexNames($this->valueTable()));
    }

    public function testARollbackReversesWithinABatchInTheReverseOfApplicationOrder(): void
    {
        $connection = $this->recording();
        $migrations = $this->fixtures(
            [FixtureSet::VALUE, FixtureSet::META, FixtureSet::OBJECT_INDEX],
            $connection,
        );
        $this->runner($migrations, $connection)->migrate();

        $plan = $this->runner($migrations, $connection)->rollbackPlan(1);

        self::assertSame(1, $plan->batch);
        self::assertSame([
            'fixture/0003_add_object_index',
            'fixture/0002_create_meta_table',
            'fixture/0001_create_value_table',
        ], $plan->names());

        $run = $this->runner($migrations, $connection)->rollback(1);

        self::assertSame($plan->names(), $run->migrations);
        self::assertSame([], $this->ledgerStore($connection)->applied());
        self::assertFalse($this->tableExists($this->valueTable()));
        self::assertFalse($this->tableExists($this->metaTable()));
    }

    public function testARollbackOfTwoBatchesWalksBothBackwardsInOneOrder(): void
    {
        $connection = $this->recording();

        $this->runner($this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection), $connection)->migrate();
        $this->runner(
            $this->fixtures([FixtureSet::VALUE, FixtureSet::META, FixtureSet::OBJECT_INDEX], $connection),
            $connection,
        )->migrate();

        $plan = $this->runner(
            $this->fixtures([FixtureSet::VALUE, FixtureSet::META, FixtureSet::OBJECT_INDEX], $connection),
            $connection,
        )->rollbackPlan(2);

        self::assertSame(1, $plan->batch);
        self::assertSame([
            'fixture/0003_add_object_index',
            'fixture/0002_create_meta_table',
            'fixture/0001_create_value_table',
        ], $plan->names());
    }

    public function testRollingBackMoreBatchesThanExistStopsAtTheFirst(): void
    {
        $connection = $this->recording();
        $migrations = $this->fixtures([FixtureSet::VALUE], $connection);
        $this->runner($migrations, $connection)->migrate();

        $plan = $this->runner($migrations, $connection)->rollbackPlan(9);

        self::assertSame(1, $plan->batch);
        self::assertSame(['fixture/0001_create_value_table'], $plan->names());
    }

    public function testAnIrreversibleMigrationRefusesTheWholeBatchBeforeASingleStatementRuns(): void
    {
        $connection = $this->recording();
        $migrations = $this->fixtures(
            [FixtureSet::VALUE, FixtureSet::OBJECT_INDEX, FixtureSet::DROP_DECIMAL],
            $connection,
        );
        $runner = $this->runner($migrations, $connection);
        $runner->migrate();

        $connection->reset();

        $exception = null;
        try {
            $runner->rollback(1);
        } catch (MigrationRollbackRefused $refusal) {
            $exception = $refusal;
        }

        self::assertInstanceOf(MigrationRollbackRefused::class, $exception);
        self::assertSame(['fixture/0004_drop_decimal_column'], $exception->migrations());
        self::assertSame([], $connection->writes(), 'the refusal runs before the first statement');

        // Nothing was half reversed: the ledger is intact and so is the schema.
        self::assertCount(3, $this->ledgerStore($connection)->applied());
        self::assertContains('field_object', $this->indexNames($this->valueTable()));
        self::assertTrue($this->tableExists($this->valueTable()));
    }

    public function testTheRollbackPlanIsRefusedAsWellAsTheRollbackItself(): void
    {
        $connection = $this->recording();
        $migrations = $this->fixtures([FixtureSet::VALUE, FixtureSet::DROP_DECIMAL], $connection);
        $runner = $this->runner($migrations, $connection);
        $runner->migrate();

        $this->expectException(MigrationRollbackRefused::class);

        $runner->rollbackPlan(1);
    }

    public function testRefusingIsNotTheSameAsFailingSoTheExitCodeDiffers(): void
    {
        $connection = $this->recording();
        $migrations = $this->fixtures([FixtureSet::VALUE, FixtureSet::DROP_DECIMAL], $connection);
        $runner = $this->runner($migrations, $connection);
        $runner->migrate();

        $refusal = null;
        try {
            $runner->rollback(1);
        } catch (MigrationRollbackRefused $failure) {
            $refusal = $failure;
        }

        self::assertNotNull($refusal);
        self::assertSame(\Iniznet\Mahout\Db\CliExitCode::Refused, \Iniznet\Mahout\Db\CliExitCode::forFailure($refusal));
    }

    public function testRollingBackAnEmptyLedgerDoesNothingAtAll(): void
    {
        $connection = $this->recording();
        $runner = $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection);

        $run = $runner->rollback(1);

        self::assertTrue($run->isEmpty());
        self::assertSame(0, $run->batch);
        self::assertSame([], $connection->writes());
    }

    public function testAMigrationTheLedgerRecordsButTheCodeNoLongerRegistersIsRefused(): void
    {
        $connection = $this->recording();
        $this->ledgerStore($connection)->install();
        $this->ledgerStore($connection)->record(
            'fixture/0009_withdrawn',
            1,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $this->expectException(MigrationMissing::class);

        $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection)->rollback(1);
    }

    public function testTheSchemaVersionIsRecordedAgainAfterAReversal(): void
    {
        $connection = $this->recording();
        $migrations = $this->fixtures([FixtureSet::VALUE], $connection);
        $runner = $this->runner($migrations, $connection);
        $runner->migrate();

        \delete_option(self::LEDGER_OPTION);
        $runner->rollback(1);

        self::assertSame(self::CODE_VERSION, (int) \get_option(self::LEDGER_OPTION, 0));
    }
}
