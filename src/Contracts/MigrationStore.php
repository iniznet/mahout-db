<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Contracts;

/**
 * The migration ledger: one row per applied migration.
 *
 * The ledger is the source of truth for what has run. The stored schema version
 * is only a cheap gate that keeps a run path off the database entirely when
 * nothing is pending.
 */
interface MigrationStore
{
    /** Whether the ledger table exists. A read; it creates nothing. */
    public function exists(): bool;

    /**
     * Create the ledger table if it is absent.
     *
     * The ledger has to exist before the first migration can be recorded, so it
     * is bootstrapped ahead of the ledger itself and idempotently. This is the
     * only statement in the package that is not owned by a migration.
     */
    public function install(): void;

    /** The highest batch applied, or 0 when none has. */
    public function latestBatch(): int;

    /**
     * Every applied migration name, in application order.
     *
     * @return list<string>
     */
    public function applied(): array;

    /**
     * The names recorded in one batch, in application order.
     *
     * @return list<string>
     */
    public function namesInBatch(int $batch): array;

    /** Record one applied migration. */
    public function record(string $migration, int $batch, \DateTimeImmutable $ranAt): void;

    /** Remove one migration's row, because it was reversed. */
    public function forget(string $migration): void;
}
