<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\AddSearchIndex;
use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\DbProvider;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Hooks;
use Iniznet\Mahout\Db\Internal\OptionSearchIndexPresence;
use Iniznet\Mahout\Db\SearchIndex;
use Iniznet\Mahout\Db\Tests\TestCase;
use Iniznet\Mahout\Kernel\Container;

/**
 * The search index migration and its cached presence.
 *
 * @internal
 */
final class SearchIndexTest extends TestCase
{
    public function testTheMigrationAddsAndDropsTheIndex(): void
    {
        $index = $this->index();
        $migration = $this->migration($index);

        self::assertSame('mahout/search_index', $migration->name());
        self::assertNull($migration->irreversibleReason());

        $migration->up();
        self::assertContains('howdah_search', $this->indexNames($this->posts()));

        $migration->down();
        self::assertNotContains('howdah_search', $this->indexNames($this->posts()));
    }

    public function testTheIndexColumnListIsExactlyTheMatchColumns(): void
    {
        $this->migration($this->index())->up();

        self::assertSame(['post_title', 'post_excerpt', 'post_content'], $this->reportedColumns());
        self::assertSame(['post_title', 'post_excerpt', 'post_content'], $this->index()->columns());
    }

    public function testRefreshCachesPresenceInANonAutoloadedOption(): void
    {
        $index = $this->index();
        $presence = new OptionSearchIndexPresence($this->connection(), $index);
        $this->migration($index)->up();

        self::assertTrue($presence->refresh());
        self::assertTrue($presence->present());

        global $wpdb;
        $autoload = $wpdb->get_var($wpdb->prepare(
            'SELECT autoload FROM '.$wpdb->options.' WHERE option_name = %s LIMIT 1',
            self::SEARCH_OPTION,
        ));
        self::assertContains($autoload, ['off', 'no']);
    }

    public function testPresentIssuesNoSchemaQuery(): void
    {
        $index = $this->index();
        $presence = new OptionSearchIndexPresence($this->connection(), $index);
        $this->migration($index)->up();
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
        $presence = new OptionSearchIndexPresence($this->connection(), $index);
        $this->migration($index)->up();

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
        $presence = new OptionSearchIndexPresence($this->connection(), $index);
        $migration = $this->migration($index);

        $migration->up();
        self::assertTrue($presence->refresh());

        $migration->down();
        self::assertFalse($presence->refresh());
        self::assertFalse($presence->present());
    }

    public function testAfterMigrateRefreshesThePresenceOption(): void
    {
        $index = $this->index();
        $migration = $this->migration($index);

        \add_filter(
            Hooks::MIGRATIONS,
            static fn (array $migrations): array => [...$migrations, $migration],
            accepted_args: 1,
        );

        $container = new Container();
        $container->set($this->diagnostics());
        $provider = new DbProvider();
        $provider->register($container);
        $provider->boot($container);

        \do_action('after_switch_theme');

        self::assertContains('howdah_search', $this->indexNames($this->posts()));
        self::assertTrue((new OptionSearchIndexPresence($this->connection(), $index))->present());
    }

    private function index(): SearchIndex
    {
        return SearchIndex::onPosts($this->prefix());
    }

    private function migration(SearchIndex $index): AddSearchIndex
    {
        return new AddSearchIndex($this->connection(), new DdlEmitter(), $index);
    }

    private function posts(): string
    {
        global $wpdb;

        return (string) $wpdb->posts;
    }

    /**
     * @return list<string>
     */
    private function reportedColumns(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND INDEX_TYPE = %s'
            .' ORDER BY SEQ_IN_INDEX',
            $this->posts(),
            'howdah_search',
            'FULLTEXT',
        ), ARRAY_A);

        return \array_map(strval(...), \array_column((array) $rows, 'COLUMN_NAME'));
    }
}
