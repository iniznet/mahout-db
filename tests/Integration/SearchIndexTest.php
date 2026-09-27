<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\AddSearchIndex;
use Iniznet\Mahout\Db\DbProvider;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Exception\MigrationIrreversible;
use Iniznet\Mahout\Db\Hooks;
use Iniznet\Mahout\Db\Index;
use Iniznet\Mahout\Db\IndexColumn;
use Iniznet\Mahout\Db\Internal\OptionSearchIndexPresence;
use Iniznet\Mahout\Db\Internal\SearchIndexFinder;
use Iniznet\Mahout\Db\RenameSearchIndex;
use Iniznet\Mahout\Db\SearchIndex;
use Iniznet\Mahout\Db\Tests\TestCase;
use Iniznet\Mahout\Kernel\Container;

/**
 * The search index: the name it carries, the migration that adds it, the
 * migration that adopts a name this package used to give it, and the presence
 * cache both of them answer through.
 *
 * @internal
 */
final class SearchIndexTest extends TestCase
{
    /** The name this package shipped with, taken from its first host. */
    private const string LEGACY = 'howdah_search';

    /** A covering index somebody else named; not ours to rename or to drop. */
    private const string NEIGHBOUR = 'neighbour_search';

    private const string DERIVED = 'mahout_posts_search';

    public function testTheDeclarationNamesTheEntityAndNotTheProjectThatShippedFirst(): void
    {
        self::assertSame(self::DERIVED, SearchIndex::NAME);
        self::assertSame([self::LEGACY], SearchIndex::legacyNames());
        self::assertSame(self::DERIVED, $this->index()->name());
    }

    public function testTheMigrationAddsAndDropsTheIndex(): void
    {
        $index = $this->index();
        $migration = $this->addMigration($index);

        self::assertSame('mahout/search_index', $migration->name());
        self::assertNull($migration->irreversibleReason());

        $migration->up();
        self::assertContains(self::DERIVED, $this->indexNames($this->posts()));

        $migration->down();
        self::assertNotContains(self::DERIVED, $this->indexNames($this->posts()));
    }

    public function testTheIndexColumnListIsExactlyTheMatchColumns(): void
    {
        $this->addMigration($this->index())->up();

        self::assertSame(['post_title', 'post_excerpt', 'post_content'], $this->columnsOf(self::DERIVED));
        self::assertSame(['post_title', 'post_excerpt', 'post_content'], $this->index()->columns());
    }

    public function testAddingTwiceCreatesNoSecondIndex(): void
    {
        $migration = $this->addMigration($this->index());

        $migration->up();
        $migration->up();

        self::assertCount(1, $this->coveringIndexes());
    }

    /**
     * The defect this migration refuses: two FULLTEXT indexes over identical
     * columns of a shared core table double the write cost of every save and
     * isolate nothing, because MATCH() selects by column list.
     */
    public function testAnIndexSomebodyElseNamedIsNotDuplicated(): void
    {
        $this->createFullTextIndex(self::NEIGHBOUR);

        $this->addMigration($this->index())->up();

        self::assertSame([self::NEIGHBOUR], $this->coveringIndexes());
    }

    public function testDownNeverDropsAnIndexItDidNotName(): void
    {
        $this->createFullTextIndex(self::NEIGHBOUR);

        $migration = $this->addMigration($this->index());
        $migration->up();
        $migration->down();

        self::assertSame([self::NEIGHBOUR], $this->coveringIndexes());
    }

    public function testRefreshCachesPresenceInANonAutoloadedOption(): void
    {
        $index = $this->index();
        $presence = $this->presence($index);
        $this->addMigration($index)->up();

        self::assertTrue($presence->refresh());
        self::assertTrue($presence->present());

        global $wpdb;
        $autoload = $wpdb->get_var($wpdb->prepare(
            'SELECT autoload FROM '.$wpdb->options.' WHERE option_name = %s LIMIT 1',
            self::SEARCH_OPTION,
        ));
        self::assertContains($autoload, ['off', 'no']);
    }

    /**
     * Presence answers the question MATCH() asks. A site whose covering index
     * carries a name from this package's own history has a working index.
     */
    public function testPresenceIsAnsweredByTheColumnListNotTheName(): void
    {
        $this->createFullTextIndex(self::LEGACY);

        $presence = $this->presence($this->index());

        self::assertTrue($presence->refresh());
        self::assertTrue($presence->present());
    }

    public function testPresentIssuesNoSchemaQuery(): void
    {
        $index = $this->index();
        $presence = $this->presence($index);
        $this->addMigration($index)->up();
        $presence->refresh();

        $cursor = $this->statementCount();
        self::assertTrue($presence->present());
        self::assertTrue($presence->present());

        foreach ($this->statementsSince($cursor) as $statement) {
            self::assertStringNotContainsString('information_schema', $statement);
        }
    }

    public function testTheDetectionQueryIsIssuedOnceByRefreshAndNeverByPresent(): void
    {
        $index = $this->index();
        $presence = $this->presence($index);
        $this->addMigration($index)->up();

        $cursor = $this->statementCount();
        $presence->refresh();
        $presence->present();

        $detections = 0;
        foreach ($this->statementsSince($cursor) as $statement) {
            if (\str_contains($statement, 'information_schema')) {
                ++$detections;
            }
        }

        self::assertSame(1, $detections);
    }

    public function testAbsenceIsReportedWhenTheIndexIsDropped(): void
    {
        $index = $this->index();
        $presence = $this->presence($index);
        $migration = $this->addMigration($index);

        $migration->up();
        self::assertTrue($presence->refresh());

        $migration->down();
        self::assertFalse($presence->refresh());
        self::assertFalse($presence->present());
    }

    public function testTheRenameAdoptsAPackagesOwnEarlierName(): void
    {
        $this->createFullTextIndex(self::LEGACY);

        $migration = $this->renameMigration($this->index());
        self::assertSame('mahout/rename_search_index', $migration->name());

        $migration->up();

        self::assertSame([self::DERIVED], $this->coveringIndexes());
        self::assertSame(['post_title', 'post_excerpt', 'post_content'], $this->columnsOf(self::DERIVED));
    }

    /**
     * The one case the rename must not touch: a covering index another system
     * named already serves MATCH(), so renaming it would be a change to someone
     * else's schema for no benefit to this site.
     */
    public function testTheRenameLeavesAForeignNameAlone(): void
    {
        $this->createFullTextIndex(self::NEIGHBOUR);

        $this->renameMigration($this->index())->up();

        self::assertSame([self::NEIGHBOUR], $this->coveringIndexes());
    }

    public function testTheRenameAddsNothingWhenNoIndexExists(): void
    {
        $this->renameMigration($this->index())->up();

        self::assertSame([], $this->coveringIndexes());
    }

    public function testTheRenameIsAlreadyCompleteAndSoIsIdempotent(): void
    {
        $index = $this->index();
        $this->addMigration($index)->up();

        $migration = $this->renameMigration($index);
        $migration->up();
        $migration->up();

        self::assertSame([self::DERIVED], $this->coveringIndexes());
    }

    public function testTheRenameRefusesToReverseRatherThanGuess(): void
    {
        $index = $this->index();
        $this->createFullTextIndex(self::LEGACY);
        $this->renameMigration($index)->up();

        try {
            $this->renameMigration($index)->down();
            self::fail('a reversal that cannot tell which name the site had must refuse');
        } catch (MigrationIrreversible $failure) {
            self::assertStringContainsString('is not recorded', $failure->getMessage());
        }

        self::assertSame([self::DERIVED], $this->coveringIndexes());
    }

    /**
     * A host registers the package's migrations on mahout/db/migrations, which is
     * how howdah declares them, and the run applies them and refreshes presence.
     *
     * This is the shape the migration constructors are locked to: a host passes
     * the connection, the emitter and the declaration, and nothing else. Widening
     * that signature is a change to every consumer, and the only way to notice it
     * here is to register them the way a consumer does.
     */
    public function testAHostRegistersThemAndTheRunAppliesThem(): void
    {
        $index = $this->index();
        $connection = $this->connection();
        $emitter = new DdlEmitter();

        \add_filter(
            Hooks::MIGRATIONS,
            static fn (array $migrations): array => [
                ...$migrations,
                new AddSearchIndex($connection, $emitter, $index),
                new RenameSearchIndex($connection, $emitter, $index),
            ],
            accepted_args: 1,
        );

        $container = new Container();
        $container->set($this->diagnostics());

        $provider = new DbProvider();
        $provider->register($container);
        $provider->boot($container);

        \do_action('after_switch_theme');

        self::assertSame([self::DERIVED], $this->coveringIndexes());
        self::assertTrue($this->presence($index)->present());
    }

    private function index(): SearchIndex
    {
        return SearchIndex::onPosts($this->prefix());
    }

    private function finder(SearchIndex $index): SearchIndexFinder
    {
        return new SearchIndexFinder($this->connection(), $index);
    }

    private function presence(SearchIndex $index): OptionSearchIndexPresence
    {
        return new OptionSearchIndexPresence($this->finder($index));
    }

    private function addMigration(SearchIndex $index): AddSearchIndex
    {
        return new AddSearchIndex($this->connection(), new DdlEmitter(), $index);
    }

    private function renameMigration(SearchIndex $index): RenameSearchIndex
    {
        return new RenameSearchIndex($this->connection(), new DdlEmitter(), $index);
    }

    private function createFullTextIndex(string $name): void
    {
        $statement = (new DdlEmitter())->addIndex(
            $this->index()->table(),
            Index::fullText(
                $name,
                IndexColumn::of('post_title'),
                IndexColumn::of('post_excerpt'),
                IndexColumn::of('post_content'),
            ),
        );

        global $wpdb;
        $wpdb->query($statement);
    }

    /**
     * The names of every FULLTEXT index on the posts table.
     *
     * @return list<string>
     */
    private function coveringIndexes(): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results(
            'SHOW INDEX FROM '.$this->posts()." WHERE Index_type = 'FULLTEXT'",
            ARRAY_A,
        );

        /** @var list<string> $names */
        $names = array_values(array_unique(array_map(strval(...), array_column($rows, 'Key_name'))));
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function columnsOf(string $name): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND INDEX_TYPE = %s'
            .' ORDER BY SEQ_IN_INDEX',
            $this->posts(),
            $name,
            'FULLTEXT',
        ), ARRAY_A);

        return \array_map(strval(...), \array_column((array) $rows, 'COLUMN_NAME'));
    }

    private function posts(): string
    {
        global $wpdb;

        return (string) $wpdb->posts;
    }
}
