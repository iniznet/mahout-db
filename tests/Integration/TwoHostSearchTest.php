<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\AddSearchIndex;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Hooks;
use Iniznet\Mahout\Db\Internal\OptionSearchIndexPresence;
use Iniznet\Mahout\Db\Internal\SearchIndexFinder;
use Iniznet\Mahout\Db\Search\IndexedSearchSwap;
use Iniznet\Mahout\Db\Search\MatchClause;
use Iniznet\Mahout\Db\Search\SearchFallbackReport;
use Iniznet\Mahout\Db\Search\SearchTerms;
use Iniznet\Mahout\Db\SearchIndex;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * Two mahout systems attached to core's search filters at once, in one process.
 *
 * This is the case the hook-naming question turns on, and the answer is that the swap
 * does not need a per-host hook name to be correct: the indexed path is opt-in per
 * query through a private query var, and the clause and the ordering travel on the
 * WP_Query that declared them. A second system's callback therefore returns the same
 * payload the declaring system wrote; a scoped build separates the names instead and
 * the second callback returns its first argument. Either way the search condition
 * appears once and the relevance ordering appears once.
 *
 * Those two positions are counted separately, and every needle is a constant, because
 * writing the assertion inline as a string containing parentheses produced three
 * unparseable files in a row: `MATCH (` in the search condition and `MATCH (` in the
 * ORDER BY are the same substring, so counting it answers a question nobody asked.
 *
 * @internal
 */
final class TwoHostSearchTest extends TestCase
{
    /** The search-condition position, as the clause emits it. */
    private const string CLAUSE = 'AND (MATCH (';

    /** The ordering position. */
    private const string ORDERING = 'ORDER BY MATCH (';

    /** Any relevance clause at all, for the queries that must not carry one. */
    private const string RELEVANCE = 'MATCH (';

    private IndexedSearchSwap $declaring;

    public function testASearchDeclaredByOneOfTwoSystemsCarriesOneClauseAndOneOrdering(): void
    {
        $this->attachTwoSystems();

        $query = new \WP_Query($this->declaredSearch('harbor'));
        $sql = (string) $query->request;

        self::assertSame(1, substr_count($sql, self::CLAUSE), 'two callbacks on one filter must not compose two search conditions');
        self::assertSame(1, substr_count($sql, self::ORDERING), 'the relevance ordering is applied once');
        self::assertStringNotContainsString('LIKE', $sql, 'a declared search does not also carry core LIKE path');
        self::assertNotSame([], $this->matched($query));
    }

    public function testAQueryThatNeverDeclaredTheIndexedPathIsUntouchedByEitherSystem(): void
    {
        $this->attachTwoSystems();

        $query = new \WP_Query(['s' => 'harbor', 'post_type' => 'post', 'posts_per_page' => 5, 'no_found_rows' => true]);
        $sql = (string) $query->request;

        self::assertStringNotContainsString(self::RELEVANCE, $sql, 'no declaration, so both filters returned their first argument');
        self::assertStringContainsString('LIKE', $sql);
    }

    public function testEachSearchCarriesItsOwnClauseRatherThanTheOthers(): void
    {
        $this->attachTwoSystems();

        $harbor = new \WP_Query($this->declaredSearch('harbor'));
        $glacier = new \WP_Query($this->declaredSearch('glacier'));

        self::assertSame(1, substr_count((string) $glacier->request, self::CLAUSE));
        self::assertStringContainsString('AGAINST (\'glacier\'', (string) $glacier->request);
        self::assertStringNotContainsString('AGAINST (\'glacier\'', (string) $harbor->request, 'a query must not be served another query term');
    }

    public function testASearchOverAnyPostTypeIsServedByTheIndex(): void
    {
        $this->attachTwoSystems();

        $args = $this->declaredSearch('harbor');
        $args['post_type'] = 'any';

        $query = new \WP_Query($args);

        self::assertSame(1, substr_count((string) $query->request, self::CLAUSE));
        self::assertNotSame([], $this->matched($query), 'the index covers title, excerpt and content whatever the post type');
    }

    /**
     * Two full stacks, as two providers would boot them: each with its own clause,
     * fallback report and swap, both attached to core's filters, all reading one
     * presence cache — which is what the container gives a real host.
     */
    private function attachTwoSystems(): void
    {
        $index = SearchIndex::onPosts($this->prefix());
        $presence = new OptionSearchIndexPresence(new SearchIndexFinder($this->connection(), $index), self::identity());

        (new AddSearchIndex($this->connection(), new DdlEmitter(), $index))->up();

        // Creating the index is the migration's act; recording that it exists is the
        // after_migrate listener's. Without the refresh the swap correctly answers
        // "not indexed" and a search takes core's path — which is exactly what this
        // first caught: the test had built the schema but not the cached fact.
        self::assertTrue($presence->refresh());

        $this->declaring = $this->swap($presence);

        foreach ([1, 2] as $ignored) {
            $swap = $this->swap($presence);

            \add_filter(Hooks::POSTS_SEARCH, $swap->search(...), priority: 10, accepted_args: 2);
            \add_filter(Hooks::POSTS_SEARCH_ORDERBY, $swap->orderby(...), priority: 10, accepted_args: 2);
        }

        $this->seedPosts();
    }

    private function swap(OptionSearchIndexPresence $presence): IndexedSearchSwap
    {
        return new IndexedSearchSwap(
            clause: MatchClause::fromWordPress(),
            fallback: new SearchFallbackReport(presence: $presence, diagnostics: $this->diagnostics()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function declaredSearch(string $term): array
    {
        return ['post_type' => 'post', 'posts_per_page' => 5, 'no_found_rows' => true]
            + $this->declaring->args(SearchTerms::fromString($term));
    }

    private function seedPosts(): void
    {
        global $wpdb;

        $fixtures = [
            ['Harbor Lights', 'The harbor lights guide ships home at dusk.'],
            ['Glacier Walk', 'The glacier groans at noon.'],
        ];

        foreach ($fixtures as [$title, $body]) {
            self::factory()->post->create([
                'post_title' => $title,
                'post_content' => $body,
                'post_date' => '2024-01-01 00:00:00',
            ]);
        }

        // A FULLTEXT index answers committed rows; rows written inside the per-test
        // transaction are not matchable yet.
        $wpdb->query('COMMIT');
    }

    /**
     * @return list<int>
     */
    private function matched(\WP_Query $query): array
    {
        return array_map(static fn (object $post): int => (int) $post->ID, $query->posts);
    }
}
