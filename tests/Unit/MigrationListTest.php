<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Unit;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Exception\InvalidMigrationList;
use Iniznet\Mahout\Db\Exception\MigrationNameCollision;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\MigrationList;
use Iniznet\Mahout\Db\Tests\Fixtures\AddsTheIntegerIndex;
use Iniznet\Mahout\Db\Tests\Fixtures\CreatesTheMetaTable;
use Iniznet\Mahout\Db\Tests\Fixtures\CreatesTheValueTable;
use Iniznet\Mahout\Db\Tests\Fixtures\DropsTheDecimalColumn;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The registered set: its order is the contract, and its names must be unique.
 *
 * @internal
 */
final class MigrationListTest extends TestCase
{
    public function testTheRegisteredOrderIsPreservedExactly(): void
    {
        self::assertSame(
            [
                'fixture/0001_create_value_table',
                'fixture/0002_create_meta_table',
                'fixture/0003_add_object_index',
                'fixture/0004_drop_decimal_column',
            ],
            $this->list()->names(),
        );
        self::assertCount(4, $this->list()->all());
    }

    public function testAMigrationIsFoundByItsName(): void
    {
        $list = $this->list();

        self::assertInstanceOf(CreatesTheMetaTable::class, $list->named('fixture/0002_create_meta_table'));
        self::assertNull($list->named('fixture/9999_not_registered'));
    }

    public function testTwoMigrationsWithTheSameNameAreRefusedAtRegistration(): void
    {
        $this->expectException(MigrationNameCollision::class);

        MigrationList::fromHookPayload([$this->migrations()[0], $this->migrations()[0]]);
    }

    public function testAPayloadEntryThatIsNotAMigrationIsRefusedAtRegistration(): void
    {
        $this->expectException(InvalidMigrationList::class);

        MigrationList::fromHookPayload(['fixture/0001_create_value_table']);
    }

    public function testAnEmptyPayloadIsAnEmptyList(): void
    {
        $list = MigrationList::fromHookPayload([]);

        self::assertSame([], $list->all());
        self::assertSame([], $list->names());
        self::assertNull($list->named('anything'));
    }

    /**
     * @return list<Migration>
     */
    private function migrations(): array
    {
        $connection = $this->connection();
        $notes = $this->notesTable();

        return [
            new CreatesTheValueTable($connection, $notes),
            new CreatesTheMetaTable(
                connection: $connection,
                emitter: new DdlEmitter(),
                name: Identifier::prefixed($this->prefix(), 'fixture_meta'),
                charsetCollate: $this->charsetCollate(),
            ),
            new AddsTheIntegerIndex($connection, new DdlEmitter(), $notes->name()),
            new DropsTheDecimalColumn($connection, new DdlEmitter(), $notes->name()),
        ];
    }

    private function list(): MigrationList
    {
        return MigrationList::fromHookPayload($this->migrations());
    }
}
