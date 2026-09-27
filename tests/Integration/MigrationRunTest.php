<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Exception\MigrationFailed;
use Iniznet\Mahout\Db\Hooks;
use Iniznet\Mahout\Db\Tests\Fixtures\FixtureSet;
use Iniznet\Mahout\Db\Tests\TestCase;
use Iniznet\Mahout\Kernel\Level;

/**
 * A run: what it applies, what it records, and what it refuses to record.
 *
 * @internal
 */
final class MigrationRunTest extends TestCase
{
    public function testAPlanBeforeAnythingRanIsTheWholeSetInDeclaredOrder(): void
    {
        $connection = $this->recording();
        $runner = $this->runner($this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection), $connection);

        $plan = $runner->plan();

        self::assertSame(
            ['fixture/0001_create_value_table', 'fixture/0002_create_meta_table'],
            $plan->pending,
        );
        self::assertSame([], $plan->applied);
        self::assertSame(1, $plan->batch);
        self::assertFalse($plan->isEmpty());
    }

    public function testAnEmptyPlanIsEmpty(): void
    {
        $connection = $this->recording();

        self::assertTrue($this->runner([], $connection)->plan()->isEmpty());
    }

    public function testARunAppliesEveryPendingMigrationIntoOneBatch(): void
    {
        $connection = $this->recording();
        $runner = $this->runner($this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection), $connection);

        $run = $runner->migrate();

        self::assertSame(1, $run->batch);
        self::assertSame(
            ['fixture/0001_create_value_table', 'fixture/0002_create_meta_table'],
            $run->migrations,
        );
        self::assertSame(2, $run->count());
        self::assertFalse($run->isEmpty());
        self::assertTrue($this->tableExists($this->valueTable()));
        self::assertTrue($this->tableExists($this->metaTable()));
    }

    public function testEveryAppliedMigrationIsRecordedWithItsBatchAndItsTimestamp(): void
    {
        $connection = $this->recording();
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection)->migrate();

        $rows = $connection->rows('SELECT migration, batch, ran_at FROM '.$this->ledgerName());

        self::assertSame('fixture/0001_create_value_table', $rows[0]['migration']);
        self::assertSame('1', $rows[0]['batch']);

        $ranAt = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (string) $rows[0]['ran_at'],
            new \DateTimeZone('UTC'),
        );
        self::assertInstanceOf(\DateTimeImmutable::class, $ranAt);
        self::assertLessThanOrEqual(60, \abs($ranAt->getTimestamp() - $before->getTimestamp()));
    }

    public function testTheSchemaVersionIsRecordedOnlyAfterTheWholeBatchCommitted(): void
    {
        $connection = $this->recording();

        self::assertSame(0, (int) \get_option(self::ledgerOption(), 0));

        $this->runner($this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection), $connection)->migrate();

        self::assertSame(self::CODE_VERSION, (int) \get_option(self::ledgerOption(), 0));
    }

    public function testTheSchemaVersionOptionIsNotAutoloaded(): void
    {
        $connection = $this->recording();
        $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection)->migrate();

        global $wpdb;

        $autoload = $wpdb->get_var($wpdb->prepare(
            'SELECT autoload FROM '.$wpdb->options.' WHERE option_name = %s LIMIT 1',
            self::ledgerOption(),
        ));

        // WordPress 6.6 renamed the stored spelling: 'no' became 'off'. Either
        // one means the option is absent from the autoload payload.
        self::assertContains($autoload, ['off', 'no']);
    }

    public function testASecondRunWithNothingPendingIsEmptyAndReusesTheBatchNumber(): void
    {
        $connection = $this->recording();
        $migrations = $this->fixtures([FixtureSet::VALUE], $connection);

        $this->runner($migrations, $connection)->migrate();
        $second = $this->runner($migrations, $connection)->migrate();

        self::assertTrue($second->isEmpty());
        self::assertSame(['fixture/0001_create_value_table'], $this->ledgerStore($connection)->applied());
        self::assertSame(1, $this->ledgerStore($connection)->latestBatch());
    }

    public function testANewlyRegisteredMigrationRunsInTheNextBatch(): void
    {
        $connection = $this->recording();

        $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection)->migrate();

        $second = $this->runner(
            $this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection),
            $connection,
        )->migrate();

        self::assertSame(2, $second->batch);
        self::assertSame(['fixture/0002_create_meta_table'], $second->migrations);
        self::assertSame(2, $this->ledgerStore($connection)->latestBatch());
    }

    public function testTheStatusSeparatesWhatIsAppliedFromWhatIsPending(): void
    {
        $connection = $this->recording();

        $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection)->migrate();

        $status = $this->runner(
            $this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection),
            $connection,
        )->status();

        self::assertSame(['fixture/0001_create_value_table'], $status->applied);
        self::assertSame(['fixture/0002_create_meta_table'], $status->pending);
        self::assertSame(self::CODE_VERSION, $status->codeVersion);
        self::assertSame(self::CODE_VERSION, $status->storedVersion);
        self::assertFalse($status->versionPending());
    }

    public function testAMigrationThatThrowsIsRecordedAtCriticalAndWrapped(): void
    {
        $connection = $this->recording();
        $cause = new \RuntimeException('the table is already there');
        $migrations = [
            ...$this->fixtures([FixtureSet::VALUE], $connection),
            $this->failing('fixture/0002_explodes', $cause),
        ];
        $diagnostics = $this->diagnostics();
        $runner = $this->runner($migrations, $connection, $diagnostics);

        $exception = null;
        try {
            $runner->migrate();
        } catch (MigrationFailed $failure) {
            $exception = $failure;
        }

        self::assertInstanceOf(MigrationFailed::class, $exception);
        self::assertSame('fixture/0002_explodes', $exception->migration());
        self::assertSame($cause, $exception->failure());

        $records = $diagnostics->records();
        self::assertCount(1, $records);
        self::assertSame(Level::Critical, $records[0]->level);
        self::assertSame('fixture/0002_explodes', $records[0]->context['migration'] ?? null);
        self::assertSame($cause, $records[0]->context['exception'] ?? null);
    }

    public function testAFailedMigrationLeavesNoLedgerRowAndRecordsNoVersion(): void
    {
        $connection = $this->recording();
        $migrations = [
            ...$this->fixtures([FixtureSet::VALUE], $connection),
            $this->failing('fixture/0002_explodes', new \RuntimeException('boom')),
        ];
        $runner = $this->runner($migrations, $connection);

        try {
            $runner->migrate();
        } catch (MigrationFailed) {
            // asserted by the previous test; here the interesting part is the state.
        }

        self::assertSame(['fixture/0001_create_value_table'], $this->ledgerStore($connection)->applied());
        self::assertSame(0, (int) \get_option(self::ledgerOption(), 0));
    }

    public function testTheMigrationFailedHookFiresWithTheNameAndTheCause(): void
    {
        $connection = $this->recording();
        $cause = new \RuntimeException('boom');
        $seen = [];

        \add_action(Hooks::MIGRATION_FAILED, static function (string $name, \Throwable $failure) use (&$seen): void {
            $seen[] = [$name, $failure];
        }, accepted_args: 2);

        try {
            $this->runner([$this->failing('fixture/0009_explodes', $cause)], $connection)->migrate();
        } catch (MigrationFailed) {
            // The hook is the assertion.
        }

        self::assertCount(1, $seen);
        self::assertSame('fixture/0009_explodes', $seen[0][0]);
        self::assertSame($cause, $seen[0][1]);
    }

    public function testTheBeforeAndAfterHooksBracketEachMigrationInOrder(): void
    {
        $connection = $this->recording();
        $events = [];

        \add_action(Hooks::BEFORE_MIGRATE, static function (string $name, int $batch) use (&$events): void {
            $events[] = ['before', $name, $batch];
        }, accepted_args: 2);
        \add_action(Hooks::AFTER_MIGRATE, static function (string $name, int $batch) use (&$events): void {
            $events[] = ['after', $name, $batch];
        }, accepted_args: 2);

        $this->runner($this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection), $connection)->migrate();

        self::assertSame([
            ['before', 'fixture/0001_create_value_table', 1],
            ['after', 'fixture/0001_create_value_table', 1],
            ['before', 'fixture/0002_create_meta_table', 1],
            ['after', 'fixture/0002_create_meta_table', 1],
        ], $events);
    }

    /**
     * A migration whose up() throws, so the failure path is provable.
     */
    private function failing(string $name, \Throwable $cause): Migration
    {
        return new class($name, $cause) implements Migration {
            public function __construct(
                private readonly string $migrationName,
                private readonly \Throwable $cause,
            ) {
            }

            public function name(): string
            {
                return $this->migrationName;
            }

            public function up(): void
            {
                throw $this->cause;
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
