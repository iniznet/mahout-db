<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\Exception\InvalidSchemaVersion;
use Iniznet\Mahout\Db\Internal\WordPressSchemaVersionStore;
use Iniznet\Mahout\Db\Tests\Fixtures\FixtureSet;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The read-only half of every command: a plan, a reversal plan and a status all
 * report without writing, including the schema version option.
 *
 * @internal
 */
final class DryRunTest extends TestCase
{
    public function testAPlanBeforeAnythingRanWritesNothing(): void
    {
        $connection = $this->recording();
        $runner = $this->runner($this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection), $connection);

        $plan = $runner->plan();

        self::assertCount(2, $plan->pending);
        self::assertSame([], $connection->writes());
        self::assertNotSame([], $connection->reads(), 'a plan still has to read to be a plan');
        self::assertFalse($this->tableExists($this->ledgerName()), 'a dry run must not create the ledger');
        self::assertSame(0, (int) \get_option(self::ledgerOption(), 0));
    }

    public function testAPlanAfterARunListsOnlyWhatIsStillPending(): void
    {
        $connection = $this->recording();

        $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection)->migrate();

        $plan = $this->runner(
            $this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection),
            $connection,
        )->plan();

        self::assertSame(['fixture/0002_create_meta_table'], $plan->pending);
        self::assertSame(['fixture/0001_create_value_table'], $plan->applied);
        self::assertSame(2, $plan->batch);
    }

    public function testAPlanWhenNothingIsPendingIsEmpty(): void
    {
        $connection = $this->recording();
        $migrations = $this->fixtures([FixtureSet::VALUE], $connection);
        $this->runner($migrations, $connection)->migrate();

        self::assertTrue($this->runner($migrations, $connection)->plan()->isEmpty());
    }

    public function testAPlanWithoutALedgerLeavesNoLedgerBehind(): void
    {
        $connection = $this->recording();

        $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection)->plan();

        self::assertSame([], $connection->writes());
        self::assertFalse($this->tableExists($this->ledgerName()));
    }

    public function testARollbackPlanWritesNothing(): void
    {
        $connection = $this->recording();
        $migrations = $this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection);
        $runner = $this->runner($migrations, $connection);
        $runner->migrate();
        $connection->reset();

        $plan = $runner->rollbackPlan(1);

        self::assertSame(['fixture/0002_create_meta_table', 'fixture/0001_create_value_table'], $plan->names());
        self::assertSame([], $connection->writes());
        self::assertTrue($this->tableExists($this->valueTable()));
        self::assertCount(2, $this->ledgerStore($connection)->applied());
    }

    public function testAStatusWritesNothing(): void
    {
        $connection = $this->recording();
        $migrations = $this->fixtures([FixtureSet::VALUE], $connection);
        $this->runner($migrations, $connection)->migrate();
        $connection->reset();

        $status = $this->runner($migrations, $connection)->status();

        self::assertSame(['fixture/0001_create_value_table'], $status->applied);
        self::assertSame([], $status->pending);
        self::assertSame([], $connection->writes());
    }

    public function testTheSchemaVersionGateReadsOneOptionAndTouchesNoTable(): void
    {
        $connection = $this->recording();
        $runner = $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection);
        $connection->reset();

        $version = $runner->schemaVersion();

        self::assertSame(self::CODE_VERSION, $version->code);
        self::assertSame(0, $version->stored);
        self::assertTrue($version->pending());
        self::assertSame([], $connection->reads(), 'the gate is an option read, not a ledger query');
    }

    public function testACorruptStoredVersionIsRefusedNeverReadAsZero(): void
    {
        \update_option(self::ledgerOption(), 'corrupt');

        try {
            (new WordPressSchemaVersionStore(self::identity()))->stored();
            self::fail('a corrupt schema version option is a broken invariant');
        } catch (InvalidSchemaVersion $refusal) {
            self::assertStringContainsString('corrupt value is refused', $refusal->getMessage());
        } finally {
            \delete_option(self::ledgerOption());
        }
    }
}
