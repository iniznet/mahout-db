<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Contract;

use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Exception\StatementFailed;
use Iniznet\Mahout\Db\Internal\WpdbMigrationStore;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The ledger contract, against the real database.
 *
 * @internal
 */
final class MigrationStoreContractTest extends TestCase
{
    public function testTheLedgerDoesNotExistUntilItIsInstalled(): void
    {
        $store = $this->store();

        self::assertFalse($store->exists());

        $store->install();

        self::assertTrue($store->exists());
        self::assertTrue($this->tableExists($this->ledgerName()));
        self::assertSame('InnoDB', $this->tableEngine($this->ledgerName()));
    }

    public function testInstallingTwiceIsNotAnError(): void
    {
        $store = $this->store();
        $store->install();
        $store->install();

        self::assertTrue($store->exists());
    }

    public function testAnEmptyLedgerReportsNoBatchAndNoMigrations(): void
    {
        $store = $this->store();
        $store->install();

        self::assertSame(0, $store->latestBatch());
        self::assertSame([], $store->applied());
        self::assertSame([], $store->namesInBatch(1));
    }

    public function testARecordedMigrationIsReadBackWithItsBatchAndItsTimestamp(): void
    {
        $store = $this->store();
        $store->install();
        $ranAt = new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'));

        $store->record('fixture/0001_create_value_table', 2, $ranAt);

        self::assertSame(['fixture/0001_create_value_table'], $store->applied());
        self::assertSame(['fixture/0001_create_value_table'], $store->namesInBatch(2));
        self::assertSame(2, $store->latestBatch());

        $rows = $this->connection()->rows('SELECT ran_at FROM '.$this->ledgerName());
        self::assertSame('2026-01-02 03:04:05', $rows[0]['ran_at']);
    }

    public function testTheAppliedNamesAreInApplicationOrderAndNotBatchOrder(): void
    {
        $store = $this->store();
        $store->install();
        $at = new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'));

        $store->record('fixture/0001', 1, $at);
        $store->record('fixture/0002', 1, $at);
        $store->record('fixture/0003', 2, $at);

        self::assertSame(['fixture/0001', 'fixture/0002', 'fixture/0003'], $store->applied());
        self::assertSame(['fixture/0001', 'fixture/0002'], $store->namesInBatch(1));
        self::assertSame(['fixture/0003'], $store->namesInBatch(2));
        self::assertSame(2, $store->latestBatch());
    }

    public function testTheSameMigrationNameCannotBeRecordedTwice(): void
    {
        $store = $this->store();
        $store->install();
        $at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $store->record('fixture/0001', 1, $at);

        $this->expectException(StatementFailed::class);

        $this->silencingDatabaseErrors(static function () use ($store, $at): void {
            $store->record('fixture/0001', 1, $at);
        });
    }

    public function testForgettingARecordRemovesItFromTheLedger(): void
    {
        $store = $this->store();
        $store->install();
        $store->record('fixture/0001', 1, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $store->record('fixture/0002', 1, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $store->forget('fixture/0001');

        self::assertSame(['fixture/0002'], $store->applied());
    }

    public function testTheLedgerCarriesAUniqueKeyOnTheMigrationName(): void
    {
        $this->store()->install();

        self::assertContains('migration', $this->indexNames($this->ledgerName()));
    }

    private function store(): WpdbMigrationStore
    {
        return new WpdbMigrationStore($this->connection(), new DdlEmitter(), $this->ledger());
    }
}
