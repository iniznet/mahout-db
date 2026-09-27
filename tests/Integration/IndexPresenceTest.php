<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Index;
use Iniznet\Mahout\Db\IndexColumn;
use Iniznet\Mahout\Db\IndexKind;
use Iniznet\Mahout\Db\Tests\Fixtures\FixtureSet;
use Iniznet\Mahout\Db\Tests\Fixtures\NotesTable;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * Index presence after a migration, read from the server rather than from the
 * declaration that produced it.
 *
 * @internal
 */
final class IndexPresenceTest extends TestCase
{
    public function testEveryIndexTheFixtureDeclaresIsPresentAfterItsMigration(): void
    {
        $connection = $this->recording();
        $this->runner(
            $this->fixtures([FixtureSet::VALUE, FixtureSet::OBJECT_INDEX], $connection),
            $connection,
        )->migrate();

        $reported = $this->indexNames($this->valueTable());
        $declared = \array_map(
            static fn (Index $index): string => $index->name->value,
            $this->notesTable()->declared()->indexes,
        );
        $declared[] = 'field_object';

        foreach ($declared as $name) {
            self::assertContains($name, $reported);
        }

        \sort($declared);
        self::assertSame($declared, $reported);
    }

    public function testTheLedgerCarriesItsPrimaryAndItsUniqueMigrationKey(): void
    {
        $this->ledgerStore($this->connection())->install();

        self::assertSame(['PRIMARY', 'migration'], $this->indexNames($this->ledgerName()));
    }

    public function testTheIndexTheMigrationAddedIsGoneAfterItsReversal(): void
    {
        $connection = $this->recording();
        $table = $this->valueTable();

        $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection)->migrate();
        $before = $this->indexNames($table);

        $adding = $this->runner(
            $this->fixtures([FixtureSet::VALUE, FixtureSet::OBJECT_INDEX], $connection),
            $connection,
        );
        $adding->migrate();
        self::assertContains('field_object', $this->indexNames($table));

        $adding->rollback(1);

        self::assertNotContains('field_object', $this->indexNames($table));
        self::assertSame($before, $this->indexNames($table));
    }

    public function testThePrefixedTextIndexIsReportedWithItsPrefixLength(): void
    {
        $connection = $this->recording();
        $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection)->migrate();

        global $wpdb;

        $rows = $wpdb->get_results('SHOW INDEX FROM '.$this->valueTable().' WHERE Key_name = '."'field_text'", ARRAY_A);

        self::assertNotEmpty($rows);
        $lengths = [];
        foreach ((array) $rows as $row) {
            $lengths[$row['Column_name']] = $row['Sub_part'];
        }

        self::assertNull($lengths['field_id']);
        self::assertSame('191', (string) $lengths['value_text']);
    }

    /**
     * The search index's declaration names exactly the MATCH columns. Creation
     * is AddSearchIndex's; this test fixes the column list both share.
     */
    public function testTheSearchIndexDeclarationNamesExactlyTheMatchColumns(): void
    {
        $index = Index::fullText(
            'mahout_posts_search',
            IndexColumn::of('post_title'),
            IndexColumn::of('post_excerpt'),
            IndexColumn::of('post_content'),
        );

        self::assertSame(['post_title', 'post_excerpt', 'post_content'], $index->columnNames());
        self::assertSame(IndexKind::FullText, $index->kind);
        self::assertSame(
            'ALTER TABLE `wp_posts` ADD FULLTEXT KEY `mahout_posts_search` (`post_title`, `post_excerpt`, `post_content`);',
            (new DdlEmitter())->addIndex(Identifier::fromString('wp_posts'), $index),
        );
        self::assertSame(
            'ALTER TABLE `wp_posts` DROP INDEX `mahout_posts_search`;',
            (new DdlEmitter())->dropIndex(Identifier::fromString('wp_posts'), $index),
        );
    }

    public function testNoLeftoverSearchIndexIsOnCoresPostTable(): void
    {
        global $wpdb;

        self::assertNotContains('mahout_posts_search', $this->indexNames($wpdb->posts));
    }

    public function testTheFixtureTableNameIsThePrefixedOne(): void
    {
        self::assertSame('wptests_fixture_field_values', NotesTable::nameFor('wptests_')->value);
        self::assertSame($this->valueTable(), $this->notesTable()->name()->value);
    }
}
