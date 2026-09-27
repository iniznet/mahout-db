<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\MigrationStore;
use Iniznet\Mahout\Db\Contracts\SchemaVersionStore;
use Iniznet\Mahout\Db\Exception\MigrationFailed;
use Iniznet\Mahout\Db\Exception\MigrationMissing;
use Iniznet\Mahout\Db\Exception\MigrationRollbackRefused;
use Iniznet\Mahout\Db\Internal\LegacyNameAdoption;
use Iniznet\Mahout\Kernel\Diagnostics;
use Iniznet\Mahout\Kernel\Level;

/**
 * The migration runner: plan, apply, reverse and report.
 *
 * Two properties are load-bearing.
 *
 * 1. A rollback decides *everything* before it executes *anything*.
 *    rollback() builds the whole plan through rollbackPlan(), which is read-only
 *    and refuses the batch when any migration in it is irreversible. A batch is
 *    therefore never half reversed.
 * 2. The stored schema version is written only after the whole batch committed,
 *    so a partially applied batch never records a version it has not reached.
 *
 * This class owns no transaction. DDL implicitly commits, so a schema change
 * and a data change never share one; the transaction boundary belongs to the
 * table gateway alone.
 */
final readonly class MigrationRunner
{
    public function __construct(
        private MigrationStore $ledger,
        private SchemaVersionStore $versions,
        private LegacyNameAdoption $legacyNames,
        private MigrationList $migrations,
        private int $codeVersion,
        private Diagnostics $diagnostics,
    ) {
    }

    /** The gate: one option read, compared to the code constant. No ledger query. */
    public function schemaVersion(): SchemaVersion
    {
        return new SchemaVersion($this->codeVersion, $this->versions->stored());
    }

    public function status(): MigrationStatus
    {
        $applied = $this->applied();

        return new MigrationStatus(
            codeVersion: $this->codeVersion,
            storedVersion: $this->versions->stored(),
            applied: $applied,
            pending: $this->names($this->pending($applied)),
        );
    }

    /** Read-only. Writes nothing, including the schema version option. */
    public function plan(): MigrationPlan
    {
        $applied = $this->applied();
        $latest = $this->ledger->exists() ? $this->ledger->latestBatch() : 0;

        return new MigrationPlan(
            pending: $this->names($this->pending($applied)),
            applied: $applied,
            batch: $latest + 1,
        );
    }

    public function migrate(): MigrationRun
    {
        // First, and before the ledger is read: an installed site's unsuffixed
        // ledger and options move under this host's identity. A plan or status
        // read does not write, so on the release that introduces the identity a
        // --status read taken before any migrate reports the whole set as pending
        // -- the ledger has not moved yet. See LegacyNameAdoption.
        $this->legacyNames->adopt();

        $this->ledger->install();

        $pending = $this->pending($this->applied());
        if ([] === $pending) {
            return new MigrationRun($this->ledger->latestBatch(), []);
        }

        $batch = $this->ledger->latestBatch() + 1;
        $ran = [];

        foreach ($pending as $migration) {
            $this->apply($migration, $batch);
            $ran[] = $migration->name();
        }

        $this->versions->record($this->codeVersion);

        return new MigrationRun($batch, $ran);
    }

    /**
     * The read-only half of a rollback, and the refusal point.
     *
     * @throws MigrationRollbackRefused before any statement runs, when any
     *                                  migration in the batch is irreversible
     */
    public function rollbackPlan(int $batches = 1): RollbackPlan
    {
        $latest = $this->ledger->exists() ? $this->ledger->latestBatch() : 0;
        if ($latest < 1) {
            return new RollbackPlan(0, []);
        }

        $first = \max(1, $latest - $batches + 1);
        $reversals = [];
        $irreversible = [];

        for ($batch = $latest; $batch >= $first; --$batch) {
            foreach (\array_reverse($this->ledger->namesInBatch($batch)) as $name) {
                $migration = $this->migrations->named($name);
                if (null === $migration) {
                    throw MigrationMissing::fromLedger($name);
                }

                if (null !== $migration->irreversibleReason()) {
                    $irreversible[] = $name;
                }

                $reversals[] = $migration;
            }
        }

        if ([] !== $irreversible) {
            throw MigrationRollbackRefused::forMigrations($irreversible);
        }

        return new RollbackPlan($first, $reversals);
    }

    public function rollback(int $batches = 1): MigrationRun
    {
        // The refusal is decided here, before a single statement has run.
        $plan = $this->rollbackPlan($batches);

        if ($plan->isEmpty()) {
            return new MigrationRun(0, []);
        }

        $reverted = [];

        foreach ($plan->reversals as $migration) {
            $this->reverse($migration, $plan->batch);
            $reverted[] = $migration->name();
        }

        $this->versions->record($this->codeVersion);

        return new MigrationRun($plan->batch, $reverted);
    }

    private function apply(Migration $migration, int $batch): void
    {
        \do_action(Hooks::BEFORE_MIGRATE, $migration->name(), $batch);

        try {
            $migration->up();
        } catch (\Throwable $failure) {
            $this->failed($migration, $failure);

            throw MigrationFailed::duringUp($migration->name(), $failure);
        }

        $this->ledger->record($migration->name(), $batch, $this->now());
        \do_action(Hooks::AFTER_MIGRATE, $migration->name(), $batch);
    }

    private function reverse(Migration $migration, int $batch): void
    {
        \do_action(Hooks::BEFORE_MIGRATE, $migration->name(), $batch);

        try {
            $migration->down();
        } catch (\Throwable $failure) {
            $this->failed($migration, $failure);

            throw MigrationFailed::duringDown($migration->name(), $failure);
        }

        $this->ledger->forget($migration->name());
        \do_action(Hooks::AFTER_MIGRATE, $migration->name(), $batch);
    }

    private function failed(Migration $migration, \Throwable $failure): void
    {
        $this->diagnostics->log(
            level: Level::Critical,
            message: 'migration failed',
            context: ['migration' => $migration->name(), 'exception' => $failure],
        );

        \do_action(Hooks::MIGRATION_FAILED, $migration->name(), $failure);
    }

    /**
     * @return list<string>
     */
    private function applied(): array
    {
        return $this->ledger->exists() ? $this->ledger->applied() : [];
    }

    /**
     * @param list<string> $applied
     *
     * @return list<Migration>
     */
    private function pending(array $applied): array
    {
        $pending = [];

        foreach ($this->migrations->all() as $migration) {
            if (!\in_array($migration->name(), $applied, true)) {
                $pending[] = $migration;
            }
        }

        return $pending;
    }

    /**
     * @param list<Migration> $migrations
     *
     * @return list<string>
     */
    private function names(array $migrations): array
    {
        return \array_map(static fn (Migration $migration): string => $migration->name(), $migrations);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
