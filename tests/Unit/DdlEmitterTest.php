<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Unit;

use Iniznet\Mahout\Db\Column;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Engine;
use Iniznet\Mahout\Db\Exception\EngineNotInnoDB;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Index;
use Iniznet\Mahout\Db\IndexColumn;
use Iniznet\Mahout\Db\MigrationLedgerSchema;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The emitted DDL, asserted byte for byte.
 *
 * A golden string is the point here: the emitter is the one place that writes
 * DDL, so the statement is a contract and not an implementation detail.
 *
 * @internal
 */
final class DdlEmitterTest extends TestCase
{
    public function testTheLedgerIsEmittedAsAnExplicitInnoDBStatement(): void
    {
        self::assertSame(
            'CREATE TABLE `'.MigrationLedgerSchema::table($this->prefix(), $this->charsetCollate())->name->value.'` ('."\n"
            ."  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
            ."  `migration` varchar(191) NOT NULL,\n"
            ."  `batch` int(10) unsigned NOT NULL,\n"
            ."  `ran_at` datetime NOT NULL,\n"
            .'  PRIMARY KEY (`id`),'."\n"
            .'  UNIQUE KEY `migration` (`migration`)'."\n"
            .') ENGINE=InnoDB '.$this->charsetCollate().';',
            (new DdlEmitter())->create(MigrationLedgerSchema::table($this->prefix(), $this->charsetCollate())),
        );
    }

    public function testTheLedgerBootstrapUsesCreateTableIfNotExists(): void
    {
        $emitter = new DdlEmitter();
        $table = MigrationLedgerSchema::table($this->prefix(), $this->charsetCollate());

        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS ', $emitter->createIfNotExists($table));
        self::assertStringStartsWith('CREATE TABLE ', $emitter->create($table));
        self::assertStringNotContainsString('IF NOT EXISTS', $emitter->create($table));
    }

    public function testACreateStatementSeparatesEachDefinitionWithACommaAndANewline(): void
    {
        $statement = (new DdlEmitter())->create($this->table());

        // Three columns and two indexes: five indented definitions, four
        // separators, and one newline before the closing parenthesis.
        self::assertSame(5, \substr_count($statement, "\n  "));
        self::assertSame(4, \substr_count($statement, ",\n"));
        self::assertSame(6, \substr_count($statement, "\n"));
        self::assertStringEndsWith(') ENGINE=InnoDB '.$this->charsetCollate().';', $statement);
    }

    public function testThePrefixIsAppliedAtDeclarationAndNeverPrependedByTheEmitter(): void
    {
        $statement = (new DdlEmitter())->create($this->table());

        self::assertStringStartsWith('CREATE TABLE `'.$this->prefix().'fixture_table` (', $statement);
        self::assertSame(1, \substr_count($statement, $this->prefix()));
    }

    public function testADropIsIdempotent(): void
    {
        self::assertSame(
            'DROP TABLE IF EXISTS `fixture_table`;',
            (new DdlEmitter())->drop(Identifier::fromString('fixture_table')),
        );
    }

    public function testAnIndexIsAddedAndDroppedWithTheSameDefinition(): void
    {
        $emitter = new DdlEmitter();
        $table = Identifier::fromString('wp_posts');
        $index = Index::fullText('howdah_search', IndexColumn::of('post_title'), IndexColumn::of('post_excerpt'), IndexColumn::of('post_content'));

        self::assertSame(
            'ALTER TABLE `wp_posts` ADD FULLTEXT KEY `howdah_search` (`post_title`, `post_excerpt`, `post_content`);',
            $emitter->addIndex($table, $index),
        );
        self::assertSame(
            'ALTER TABLE `wp_posts` DROP INDEX `howdah_search`;',
            $emitter->dropIndex($table, $index),
        );
    }

    public function testAColumnIsDroppedByIdentifier(): void
    {
        self::assertSame(
            'ALTER TABLE `fixture_table` DROP COLUMN `value_dec`;',
            (new DdlEmitter())->dropColumn(Identifier::fromString('fixture_table'), Column::decimal('value_dec', 20, 6)),
        );
    }

    public function testAnEngineThatIsNotInnoDBIsRefusedRatherThanEmitted(): void
    {
        $emitter = new DdlEmitter();

        $exception = null;
        try {
            $emitter->create($this->table(Engine::MyISAM));
        } catch (EngineNotInnoDB $failure) {
            $exception = $failure;
        }

        self::assertInstanceOf(EngineNotInnoDB::class, $exception);
        self::assertSame($this->prefix().'fixture_table', $exception->table());
        self::assertSame('MyISAM', $exception->engine());
    }

    public function testTheMyISAMRefusalHappensBeforeAnyStatementIsBuiltForMemoryToo(): void
    {
        $emitter = new DdlEmitter();

        $this->expectException(EngineNotInnoDB::class);

        $emitter->createIfNotExists($this->table(Engine::Memory));
    }

    public function testEveryTableThePackageDeclaresEmitsAnExplicitEngine(): void
    {
        $emitter = new DdlEmitter();

        foreach ($this->declarations() as $label => $table) {
            self::assertStringContainsString('ENGINE=InnoDB', $emitter->create($table), $label);
        }
    }

    public function testEveryTableThePackageDeclaresNamesItsOwnCharsetAndCollation(): void
    {
        $emitter = new DdlEmitter();

        foreach ($this->declarations() as $label => $table) {
            self::assertStringEndsWith($this->charsetCollate().';', $emitter->create($table), $label);
        }
    }

    /**
     * @return array<string, Table>
     */
    private function declarations(): array
    {
        return [
            'ledger' => MigrationLedgerSchema::table($this->prefix(), $this->charsetCollate()),
            'fixture' => $this->table(),
        ];
    }

    private function table(Engine $engine = Engine::InnoDB): Table
    {
        return new Table(
            name: Identifier::prefixed($this->prefix(), 'fixture_table'),
            columns: [
                Column::identifier('id'),
                Column::varchar('field_id', 191),
                Column::text('value_text')->nullable(),
            ],
            indexes: [
                Index::primary(IndexColumn::of('id')),
                Index::key('field_text', IndexColumn::of('field_id'), IndexColumn::prefixed('value_text', 191)),
            ],
            engine: $engine,
            charsetCollate: $this->charsetCollate(),
        );
    }
}
