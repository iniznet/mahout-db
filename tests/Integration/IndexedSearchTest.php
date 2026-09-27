<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\AddSearchIndex;
use Iniznet\Mahout\Db\Contracts\SearchIndexPresence;
use Iniznet\Mahout\Db\DbProvider;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Hooks;
use Iniznet\Mahout\Db\Internal\SearchIndexFinder;
use Iniznet\Mahout\Db\Search\IndexedSearchSwap;
use Iniznet\Mahout\Db\Search\SearchProvider;
use Iniznet\Mahout\Db\Search\SearchTerms;
use Iniznet\Mahout\Db\SearchIndex;
use Iniznet\Mahout\Db\Tests\TestCase;
use Iniznet\Mahout\Kernel\Container;

/**
 * The search path against a real database: the index the migration adds, the
 * clause the swap builds from it, and the query core then runs.
 *
 * A FULLTEXT index only reflects committed rows and core's test case runs each
 * test inside a transaction, so the fixtures are committed explicitly and this
 * test owns their removal -- DDL cannot be rolled back, and neither can rows
 * the index has already seen.
 *
 * @internal
 */
final class IndexedSearchTest extends TestCase
{
    /** @var list<int> */
    private array $fixtures = [];

    private ?Container $container = null;

    /**
     * Whether this test has already added the index.
     *
     * Adding it twice in one test is a duplicate-key error: DDL is not
     * idempotent, and one test may run more than one declared search.
     */
    private bool $indexAdded = false;

    public function setUp(): void
    {
        parent::setUp();

        $this->fixtures = $this->createFixtures();
    }

    public function tearDown(): void
    {
        global $wpdb;

        foreach ($this->fixtures as $postId) {
            \wp_delete_post($postId, true);
        }

        $this->fixtures = [];
        $wpdb->query('COMMIT');

        parent::tearDown();
    }

    public function testTheIndexTheMigrationAddsIsTheOneTheClauseNames(): void
    {
        $this->addSearchIndex();

        self::assertSame(
            ['post_title', 'post_excerpt', 'post_content'],
            $this->reportedColumns(),
            "the server's own column list is the list the clause is built from, so error 1191 cannot arise.",
        );
    }

    public function testADeclaredSearchRunsTheMatchAndOrdersByItsScore(): void
    {
        $query = $this->search('harbor', indexed: true);

        self::assertStringContainsString(
            'MATCH (post_title, post_excerpt, post_content) AGAINST (\'harbor\' IN NATURAL LANGUAGE MODE)',
            (string) $query->request,
        );
        self::assertStringContainsString('ORDER BY MATCH (post_title', (string) $query->request);
        self::assertStringNotContainsString('post_title LIKE', (string) $query->request, 'core\'s LIKE fragment is replaced, not supplemented.');
        self::assertNotSame([], $this->ids($query), 'the declared search matches the fixtures.');
    }

    public function testTheOrderingFollowsTheTermAndNotTheDate(): void
    {
        $harbor = $this->ids($this->search('harbor', indexed: true));
        $canyon = $this->ids($this->search('canyon', indexed: true));

        self::assertNotSame([], $harbor);
        self::assertNotSame([], $canyon);
        self::assertNotSame($harbor, $canyon, 'the order is the index\'s score; one fixture date would give one order.');

        foreach ($harbor as $id) {
            self::assertStringContainsStringIgnoringCase('harbor', (string) \get_the_title($id), 'every hit mentions the term.');
        }
    }

    public function testWithTheIndexAbsentTheQueryIsCoresLikePathUnchanged(): void
    {
        $query = $this->search('harbor', indexed: false);

        self::assertStringNotContainsString('MATCH (', (string) $query->request, 'no flag, so both filters returned their first argument.');
        self::assertStringContainsString('LIKE', (string) $query->request);
        self::assertNotSame([], $this->ids($query), 'a site whose migration has not run still answers a search.');
    }

    public function testTheFallbackIsRecordedOnceWhateverTheNumberOfSearches(): void
    {
        $container = $this->boot();
        $diagnostics = $container->get(\Iniznet\Mahout\Kernel\Diagnostics::class);
        $swap = $container->get(IndexedSearchSwap::class);

        $swap->args(SearchTerms::fromString('harbor'));
        $swap->args(SearchTerms::fromString('canyon'));

        self::assertCount(1, $diagnostics->records(), 'one report per request, not one per query.');
    }

    public function testTheProviderAttachesExactlyTheTwoSearchFilters(): void
    {
        $search = $this->hookCount(Hooks::POSTS_SEARCH);
        $orderby = $this->hookCount(Hooks::POSTS_SEARCH_ORDERBY);

        $this->boot();

        self::assertSame($search + 1, $this->hookCount(Hooks::POSTS_SEARCH));
        self::assertSame($orderby + 1, $this->hookCount(Hooks::POSTS_SEARCH_ORDERBY));
    }

    public function testAnUndeclaredQueryIsUntouchedByTheAttachedFilters(): void
    {
        $this->addSearchIndex();
        $this->boot();

        $query = new \WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'fields' => 'ids',
            'no_found_rows' => true,
            'posts_per_page' => 5,
        ]);

        self::assertStringNotContainsString('MATCH (', (string) $query->request, 'a query that did not declare the path is core\'s query.');
    }

    private function search(string $term, bool $indexed): \WP_Query
    {
        $container = $this->boot();
        $swap = $container->get(IndexedSearchSwap::class);

        if ($indexed) {
            $this->addSearchIndex();
            self::assertTrue($container->get(SearchIndexPresence::class)->refresh());
        }

        self::assertSame($indexed, $swap->isIndexed());

        return new \WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ] + $swap->args(SearchTerms::fromString($term)));
    }

    /** @return list<int> */
    private function ids(\WP_Query $query): array
    {
        return \array_values(\array_map('intval', (array) $query->posts));
    }

    private function boot(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $container = $this->declareIdentity(new Container());
        $container->set($this->diagnostics());

        $db = new DbProvider();
        $db->register($container);

        $search = new SearchProvider();
        $search->register($container);
        $search->boot($container);

        return $this->container = $container;
    }

    private function addSearchIndex(): void
    {
        if ($this->indexAdded) {
            return;
        }

        $index = SearchIndex::onPosts($this->prefix());
        $finder = new SearchIndexFinder($this->connection(), $index);

        (new AddSearchIndex($this->connection(), new DdlEmitter(), $index, $finder))->up();

        $this->indexAdded = true;
    }

    /** @return list<int> */
    private function createFixtures(): array
    {
        $fixtures = [
            ['title' => 'Harbor Lights', 'body' => 'The harbor lights guide ships home at dusk.'],
            ['title' => 'Harbor Point', 'body' => 'A harbor, and the harbor again, both quiet.'],
            ['title' => 'Canyon Dawn', 'body' => 'A canyon at dawn is quiet.'],
            ['title' => 'Glacier Walk', 'body' => 'The glacier groans at noon.'],
        ];

        $ids = [];

        foreach ($fixtures as $fixture) {
            $ids[] = (int) self::factory()->post->create([
                'post_title' => $fixture['title'],
                'post_content' => $fixture['body'],
                'post_date' => '2024-01-01 00:00:00',
            ]);
        }

        global $wpdb;
        $wpdb->query('COMMIT');

        return $ids;
    }

    /** @return list<string> */
    private function reportedColumns(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND INDEX_TYPE = %s'
            .' ORDER BY SEQ_IN_INDEX',
            (string) $wpdb->posts,
            SearchIndex::NAME,
            'FULLTEXT',
        ), ARRAY_A);

        return \array_values(\array_map('strval', \array_column((array) $rows, 'COLUMN_NAME')));
    }
}
