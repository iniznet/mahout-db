<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Unit;

use Iniznet\Mahout\Db\Exception\ConnectionMissing;
use Iniznet\Mahout\Db\Exception\EngineNotInnoDB;
use Iniznet\Mahout\Db\Exception\InvalidColumn;
use Iniznet\Mahout\Db\Exception\InvalidIdentifier;
use Iniznet\Mahout\Db\Exception\InvalidIndex;
use Iniznet\Mahout\Db\Exception\InvalidMigrationList;
use Iniznet\Mahout\Db\Exception\InvalidSchemaVersion;
use Iniznet\Mahout\Db\Exception\InvalidTable;
use Iniznet\Mahout\Db\Exception\MahoutException;
use Iniznet\Mahout\Db\Exception\MigrationFailed;
use Iniznet\Mahout\Db\Exception\MigrationIrreversible;
use Iniznet\Mahout\Db\Exception\MigrationMissing;
use Iniznet\Mahout\Db\Exception\MigrationNameCollision;
use Iniznet\Mahout\Db\Exception\MigrationRollbackRefused;
use Iniznet\Mahout\Db\Exception\StatementFailed;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * Every named constructor of every exception in the package.
 *
 * Each one is asserted twice: that it produces its declared type, and that the
 * typed context getters carry the values the caller needs to act on.
 *
 * @internal
 */
final class ExceptionTest extends TestCase
{
    public function testEveryExceptionImplementsThePackageMarker(): void
    {
        foreach ($this->everyConstructor() as $label => $factory) {
            self::assertInstanceOf(MahoutException::class, $factory(), $label);
        }
    }

    public function testEveryExceptionIsFinalAndConstructedOnlyByName(): void
    {
        foreach ($this->everyConstructor() as $label => $factory) {
            $class = $factory()::class;
            $reflection = new \ReflectionClass($class);

            self::assertTrue($reflection->isFinal(), $label.' must be final');
            self::assertFalse($reflection->getConstructor()?->isPublic() ?? false, $label.' must not be publicly constructible');
        }
    }

    public function testAnInvalidIdentifierCarriesTheValueItRejected(): void
    {
        $failure = InvalidIdentifier::forValue('1bad');

        self::assertSame('1bad', $failure->identifier());
        self::assertStringContainsString('1bad', $failure->getMessage());
    }

    public function testATooLongIdentifierCarriesItsLengthInTheMessage(): void
    {
        $failure = InvalidIdentifier::tooLong(\str_repeat('a', 65));

        self::assertStringContainsString('64', $failure->getMessage(), 'the message names the cap');
        self::assertSame(65, \strlen($failure->identifier()));
    }

    public function testAnInvalidColumnCarriesItsColumnName(): void
    {
        self::assertSame('field_id', InvalidColumn::varcharLength('field_id', 192)->column());
        self::assertSame('value_dec', InvalidColumn::decimalShape('value_dec', 20, 30)->column());
        self::assertSame('id', InvalidColumn::autoIncrementCannotBeNullable('id')->column());
    }

    public function testAnInvalidIndexCarriesItsIndexAndItsColumn(): void
    {
        self::assertSame('field_text', InvalidIndex::noColumns('field_text')->index());

        $prefix = InvalidIndex::prefixLength('value_text', 192);
        self::assertSame('value_text', $prefix->column());
        self::assertStringContainsString('192', $prefix->getMessage());

        $unknown = InvalidIndex::unknownColumn('field_int', 'value_missing');
        self::assertSame('field_int', $unknown->index());
        self::assertSame('value_missing', $unknown->column());
    }

    public function testAnInvalidTableCarriesItsTableName(): void
    {
        self::assertSame('fixture', InvalidTable::noColumns('fixture')->table());
        self::assertSame('fixture', InvalidTable::duplicateColumn('fixture', 'id')->table());
        self::assertSame('fixture', InvalidTable::duplicateIndex('fixture', 'field_text')->table());
        self::assertSame('fixture', InvalidTable::duplicatePrimaryKey('fixture')->table());
        self::assertSame('fixture', InvalidTable::autoIncrementWithoutKey('fixture', 'id')->table());

        self::assertStringContainsString('id', InvalidTable::autoIncrementWithoutKey('fixture', 'id')->getMessage());
    }

    public function testAnEngineRefusalNamesTheEngineItRefused(): void
    {
        $failure = EngineNotInnoDB::forTable('fixture', 'MyISAM');

        self::assertSame('fixture', $failure->table());
        self::assertSame('MyISAM', $failure->engine());
        self::assertInstanceOf(\DomainException::class, $failure);
    }

    public function testAMissingConnectionNamesWpdb(): void
    {
        self::assertStringContainsString('wpdb', ConnectionMissing::withoutWpdb()->getMessage());
        self::assertInstanceOf(\RuntimeException::class, ConnectionMissing::withoutWpdb());
    }

    public function testAFailedStatementCarriesTheStatementAndTheDatabaseError(): void
    {
        $failure = StatementFailed::forStatement('CREATE TABLE x', "Table 'x' already exists");

        self::assertSame('CREATE TABLE x', $failure->statement());
        self::assertSame("Table 'x' already exists", $failure->error());
        self::assertStringContainsString("Table 'x' already exists", $failure->getMessage());
    }

    public function testAnUnreadableResultNamesTheStatement(): void
    {
        self::assertSame('SELECT 1', StatementFailed::unreadableResult('SELECT 1')->statement());
    }

    public function testAMigrationFailureCarriesTheMigrationAndTheCause(): void
    {
        $cause = new \RuntimeException('boom');

        $up = MigrationFailed::duringUp('fixture/0001', $cause);
        self::assertSame('fixture/0001', $up->migration());
        self::assertSame($cause, $up->failure());
        self::assertSame($cause, $up->getPrevious());
        self::assertStringContainsString('applying', $up->getMessage());

        $down = MigrationFailed::duringDown('fixture/0001', $cause);
        self::assertStringContainsString('reversing', $down->getMessage());
        self::assertSame($cause, $down->getPrevious());
    }

    public function testAnIrreversibleMigrationCarriesItsDeclaredReason(): void
    {
        $failure = MigrationIrreversible::because('fixture/0004', 'the dropped column is gone');

        self::assertSame('fixture/0004', $failure->migration());
        self::assertSame('the dropped column is gone', $failure->reason());
        self::assertStringContainsString('the dropped column is gone', $failure->getMessage());
        self::assertInstanceOf(\LogicException::class, $failure);
    }

    public function testAMissingMigrationNamesTheLedgerRow(): void
    {
        self::assertSame('fixture/gone', MigrationMissing::fromLedger('fixture/gone')->migration());
    }

    public function testACollidingNameCarriesTheNameAndTheSolution(): void
    {
        $failure = MigrationNameCollision::forName('fixture/0001');

        self::assertSame('fixture/0001', $failure->migration());
        self::assertStringContainsString('unique', $failure->getMessage());
    }

    public function testARefusedReversalCarriesEveryIrreversibleMigrationInTheBatch(): void
    {
        $failure = MigrationRollbackRefused::forMigrations(['fixture/0003', 'fixture/0004']);

        self::assertSame(['fixture/0003', 'fixture/0004'], $failure->migrations());
        self::assertStringContainsString('fixture/0004', $failure->getMessage());
        self::assertInstanceOf(\DomainException::class, $failure);
    }

    public function testARefusedReversalNamesEveryMigrationNotOnlyTheFirst(): void
    {
        $failure = MigrationRollbackRefused::forMigrations(['a', 'b', 'c']);

        foreach (['a', 'b', 'c'] as $name) {
            self::assertStringContainsString($name, $failure->getMessage());
        }
    }

    public function testAMigrationListRejectionNamesTheHookThatSuppliedIt(): void
    {
        self::assertStringContainsString(
            'mahout/db/migrations',
            InvalidMigrationList::notAList('mahout/db/migrations')->getMessage(),
        );
        self::assertStringContainsString(
            'mahout/db/migrations',
            InvalidMigrationList::notAMigration('mahout/db/migrations')->getMessage(),
        );
        self::assertInstanceOf(\UnexpectedValueException::class, InvalidMigrationList::notAList('x'));
    }

    public function testAnInvalidSchemaVersionNamesTheHookThatSuppliedIt(): void
    {
        self::assertStringContainsString(
            'mahout/db/schema_version',
            InvalidSchemaVersion::notAnInteger('mahout/db/schema_version')->getMessage(),
        );
    }

    /**
     * One factory per named constructor in the package. A constructor with no
     * entry here is a constructor with no test.
     *
     * @return array<string, callable(): \Throwable>
     */
    private function everyConstructor(): array
    {
        return [
            'ConnectionMissing::withoutWpdb' => ConnectionMissing::withoutWpdb(...),
            'EngineNotInnoDB::forTable' => static fn (): \Throwable => EngineNotInnoDB::forTable('fixture', 'MyISAM'),
            'InvalidColumn::varcharLength' => static fn (): \Throwable => InvalidColumn::varcharLength('c', 192),
            'InvalidColumn::decimalShape' => static fn (): \Throwable => InvalidColumn::decimalShape('c', 20, 30),
            'InvalidColumn::autoIncrementCannotBeNullable' => static fn (): \Throwable => InvalidColumn::autoIncrementCannotBeNullable('id'),
            'InvalidIdentifier::forValue' => static fn (): \Throwable => InvalidIdentifier::forValue('1bad'),
            'InvalidIdentifier::tooLong' => static fn (): \Throwable => InvalidIdentifier::tooLong(\str_repeat('a', 65)),
            'InvalidIndex::noColumns' => static fn (): \Throwable => InvalidIndex::noColumns('i'),
            'InvalidIndex::prefixLength' => static fn (): \Throwable => InvalidIndex::prefixLength('c', 192),
            'InvalidIndex::unknownColumn' => static fn (): \Throwable => InvalidIndex::unknownColumn('i', 'c'),
            'InvalidMigrationList::notAList' => static fn (): \Throwable => InvalidMigrationList::notAList('mahout/db/migrations'),
            'InvalidMigrationList::notAMigration' => static fn (): \Throwable => InvalidMigrationList::notAMigration('mahout/db/migrations'),
            'InvalidSchemaVersion::notAnInteger' => static fn (): \Throwable => InvalidSchemaVersion::notAnInteger('mahout/db/schema_version'),
            'InvalidTable::noColumns' => static fn (): \Throwable => InvalidTable::noColumns('t'),
            'InvalidTable::duplicateColumn' => static fn (): \Throwable => InvalidTable::duplicateColumn('t', 'c'),
            'InvalidTable::duplicateIndex' => static fn (): \Throwable => InvalidTable::duplicateIndex('t', 'i'),
            'InvalidTable::duplicatePrimaryKey' => static fn (): \Throwable => InvalidTable::duplicatePrimaryKey('t'),
            'InvalidTable::autoIncrementWithoutKey' => static fn (): \Throwable => InvalidTable::autoIncrementWithoutKey('t', 'id'),
            'MigrationFailed::duringUp' => static fn (): \Throwable => MigrationFailed::duringUp('m', new \RuntimeException('x')),
            'MigrationFailed::duringDown' => static fn (): \Throwable => MigrationFailed::duringDown('m', new \RuntimeException('x')),
            'MigrationIrreversible::because' => static fn (): \Throwable => MigrationIrreversible::because('m', 'reason'),
            'MigrationMissing::fromLedger' => static fn (): \Throwable => MigrationMissing::fromLedger('m'),
            'MigrationNameCollision::forName' => static fn (): \Throwable => MigrationNameCollision::forName('m'),
            'MigrationRollbackRefused::forMigrations' => static fn (): \Throwable => MigrationRollbackRefused::forMigrations(['m']),
            'StatementFailed::forStatement' => static fn (): \Throwable => StatementFailed::forStatement('s', 'e'),
            'StatementFailed::unreadableResult' => static fn (): \Throwable => StatementFailed::unreadableResult('s'),
        ];
    }
}
