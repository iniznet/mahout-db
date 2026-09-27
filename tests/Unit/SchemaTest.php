<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Unit;

use Iniznet\Mahout\Db\Column;
use Iniznet\Mahout\Db\Engine;
use Iniznet\Mahout\Db\Exception\InvalidColumn;
use Iniznet\Mahout\Db\Exception\InvalidIdentifier;
use Iniznet\Mahout\Db\Exception\InvalidIndex;
use Iniznet\Mahout\Db\Exception\InvalidTable;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Index;
use Iniznet\Mahout\Db\IndexColumn;
use Iniznet\Mahout\Db\MigrationLedgerSchema;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * Every invariant the schema objects enforce, including the rejections.
 *
 * @internal
 */
final class SchemaTest extends TestCase
{
    /**
     * @dataProvider unusableIdentifiers
     */
    public function testAnIdentifierRejectsAValueOutsideTheGrammar(string $value): void
    {
        $this->expectException(InvalidIdentifier::class);

        Identifier::fromString($value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableIdentifiers(): array
    {
        return [
            'a leading digit' => ['1st_value'],
            'a hyphen' => ['value-name'],
            'a space' => ['value name'],
            'a backtick' => ['value`name'],
            'a quote' => ["value'name"],
            'a semicolon' => ['value; DROP TABLE x'],
            'a dot' => ['wp_posts.id'],
            'empty' => [''],
        ];
    }

    public function testAnIdentifierOfSixtyFourBytesIsAcceptedAndSixtyFiveIsNot(): void
    {
        self::assertSame(64, \strlen(Identifier::fromString(\str_repeat('a', 64))->value));

        $this->expectException(InvalidIdentifier::class);
        Identifier::fromString(\str_repeat('a', 65));
    }

    public function testAnIdentifierCarriesThePrefixItWasDeclaredWith(): void
    {
        self::assertSame('wptests_mahout_migrations', Identifier::prefixed('wptests_', 'mahout_migrations')->value);
    }

    public function testAPrefixThatProducesAnUnusableNameIsRefusedAtDeclarationTime(): void
    {
        $this->expectException(InvalidIdentifier::class);

        Identifier::prefixed('wp-', 'mahout_migrations');
    }

    public function testAnIdentifierQuotesItselfForDDL(): void
    {
        self::assertSame('`value_text`', Identifier::fromString('value_text')->quoted());
    }

    public function testAnIdentifierColumnIsAutoIncrementingAndNotNull(): void
    {
        self::assertSame('`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT', Column::identifier('id')->definition());
    }

    public function testTheAutoIncrementColumnCannotBeNullable(): void
    {
        $this->expectException(InvalidColumn::class);

        Column::identifier('id')->nullable();
    }

    /**
     * @dataProvider declaredColumnTypes
     */
    public function testAColumnFactoryProducesTheTypeThePolicyFixes(string $expected, Column $column): void
    {
        self::assertSame("`{$column->name->value}` {$expected} NOT NULL", $column->definition());
    }

    /**
     * @return array<string, array{string, Column}>
     */
    public static function declaredColumnTypes(): array
    {
        return [
            'reference' => ['bigint(20) unsigned', Column::reference('object_id')],
            'bigint' => ['bigint(20)', Column::bigInt('value_int')],
            'int unsigned' => ['int(10) unsigned', Column::intUnsigned('batch')],
            'tinyint unsigned' => ['tinyint(3) unsigned', Column::tinyIntUnsigned('object_kind')],
            'varchar' => ['varchar(191)', Column::varchar('field_id', 191)],
            'text' => ['text', Column::text('value_text')],
            'decimal' => ['decimal(20, 6)', Column::decimal('value_dec', 20, 6)],
            'datetime' => ['datetime', Column::dateTime('value_date')],
        ];
    }

    public function testANullableColumnStatesNull(): void
    {
        self::assertSame('`value_text` text NULL', Column::text('value_text')->nullable()->definition());
    }

    /**
     * @dataProvider unusableVarcharLengths
     */
    public function testAVarcharLengthOutsideTheIndexCapIsRefused(int $length): void
    {
        $this->expectException(InvalidColumn::class);

        Column::varchar('field_id', $length);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unusableVarcharLengths(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'one over the cap' => [192],
        ];
    }

    /**
     * @dataProvider unusableDecimalShapes
     */
    public function testADecimalShapeThatMySQLWouldRefuseIsRefused(int $precision, int $scale): void
    {
        $this->expectException(InvalidColumn::class);

        Column::decimal('value_dec', $precision, $scale);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function unusableDecimalShapes(): array
    {
        return [
            'zero precision' => [0, 0],
            'negative precision' => [-1, 0],
            'precision over 65' => [66, 0],
            'negative scale' => [10, -1],
            'scale over precision' => [10, 11],
        ];
    }

    public function testAnIndexDeclaresItsKindAndItsColumns(): void
    {
        self::assertSame(
            'PRIMARY KEY (`object_kind`, `object_id`)',
            Index::primary(IndexColumn::of('object_kind'), IndexColumn::of('object_id'))->definition(),
        );
        self::assertSame('UNIQUE KEY `note_key` (`note_key`)', Index::unique('note_key', IndexColumn::of('note_key'))->definition());
        self::assertSame('KEY `field_int` (`value_int`)', Index::key('field_int', IndexColumn::of('value_int'))->definition());
        self::assertSame('FULLTEXT KEY `mahout_posts_search` (`post_title`)', Index::fullText('mahout_posts_search', IndexColumn::of('post_title'))->definition());
    }

    public function testAnIndexPrefixLengthIsEmittedInTheDefinition(): void
    {
        self::assertSame(
            'KEY `field_text` (`field_id`, `value_text`(191))',
            Index::key('field_text', IndexColumn::of('field_id'), IndexColumn::prefixed('value_text', 191))->definition(),
        );
    }

    /**
     * @dataProvider unusablePrefixLengths
     */
    public function testAnIndexPrefixLengthOutsideTheIndexCapIsRefused(int $length): void
    {
        $this->expectException(InvalidIndex::class);

        IndexColumn::prefixed('value_text', $length);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unusablePrefixLengths(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'one over the cap' => [192]];
    }

    public function testAnIndexWithNoColumnsIsRefused(): void
    {
        $this->expectException(InvalidIndex::class);

        Index::key('field_int');
    }

    public function testATableWithNoColumnsIsRefused(): void
    {
        $this->expectException(InvalidTable::class);

        new Table(
            name: Identifier::fromString('fixture_table'),
            columns: [],
            indexes: [],
            engine: Engine::InnoDB,
            charsetCollate: $this->charsetCollate(),
        );
    }

    public function testADuplicateColumnIsRefused(): void
    {
        $this->expectException(InvalidTable::class);

        $this->table(columns: [Column::text('value_text'), Column::text('value_text')]);
    }

    public function testADuplicateIndexNameIsRefused(): void
    {
        $this->expectException(InvalidTable::class);

        $this->table(indexes: [
            Index::key('field_text', IndexColumn::of('value_text')),
            Index::key('field_text', IndexColumn::of('value_text')),
        ]);
    }

    public function testAnIndexOverAnUndeclaredColumnIsRefused(): void
    {
        $this->expectException(InvalidIndex::class);

        $this->table(indexes: [Index::key('field_missing', IndexColumn::of('value_missing'))]);
    }

    public function testASecondPrimaryKeyIsRefused(): void
    {
        $this->expectException(InvalidTable::class);

        $this->table(indexes: [
            Index::primary(IndexColumn::of('value_text')),
            Index::primary(IndexColumn::of('note_key')),
        ]);
    }

    public function testAnAutoIncrementColumnThatNoIndexCoversIsRefused(): void
    {
        $this->expectException(InvalidTable::class);

        new Table(
            name: Identifier::fromString('fixture_table'),
            columns: [Column::identifier('id'), Column::text('value_text')],
            indexes: [],
            engine: Engine::InnoDB,
            charsetCollate: $this->charsetCollate(),
        );
    }

    public function testATableCarriesItsEngineAndItsCharsetCollationVerbatim(): void
    {
        $table = $this->table();

        self::assertSame(Engine::InnoDB, $table->engine);
        self::assertSame($this->charsetCollate(), $table->charsetCollate);
    }

    public function testTheLedgerSchemaDeclaresInnoDBAndAUniqueMigrationName(): void
    {
        $ledger = MigrationLedgerSchema::table('wptests_', $this->charsetCollate());

        self::assertSame('wptests_mahout_migrations', $ledger->name->value);
        self::assertSame(Engine::InnoDB, $ledger->engine);
        self::assertSame(['id', 'migration', 'batch', 'ran_at'], \array_map(
            static fn (Column $column): string => $column->name->value,
            $ledger->columns,
        ));

        $unique = $ledger->indexes[1] ?? null;
        self::assertNotNull($unique);
        self::assertSame('migration', $unique->name->value);
    }

    /**
     * @param list<Column> $columns
     * @param list<Index>  $indexes
     */
    private function table(array $columns = [], array $indexes = []): Table
    {
        if ([] === $columns) {
            $columns = [Column::text('value_text'), Column::text('note_key')];
        }

        if ([] === $indexes && [] !== $columns) {
            $indexes = [Index::key('field_text', IndexColumn::of($columns[0]->name->value))];
        }

        return new Table(
            name: Identifier::fromString('fixture_table'),
            columns: $columns,
            indexes: $indexes,
            engine: Engine::InnoDB,
            charsetCollate: $this->charsetCollate(),
        );
    }
}
