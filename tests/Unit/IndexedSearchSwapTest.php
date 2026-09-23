<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Unit;

use Iniznet\Mahout\Db\Exception\InvalidSearchDeclaration;
use Iniznet\Mahout\Db\Exception\UnusableSearchTerm;
use Iniznet\Mahout\Db\Search\IndexedSearchSwap;
use Iniznet\Mahout\Db\Search\MatchClause;
use Iniznet\Mahout\Db\Search\SearchFallbackReport;
use Iniznet\Mahout\Db\Search\SearchTerms;
use Iniznet\Mahout\Db\SearchIndex;
use Iniznet\Mahout\Db\Tests\Fixtures\RecordingPreparer;
use Iniznet\Mahout\Db\Tests\Fixtures\StubIndexPresence;
use Iniznet\Mahout\Db\Tests\TestCase;
use Iniznet\Mahout\Kernel\Diagnostics;

/**
 * The swap: two filters that honour a declaration and change nothing else, and
 * the args that make the declaration.
 *
 * The rule under test is that the indexed path is opt-in per query and that
 * every other query gets its own argument back. The second rule is that a
 * declaration is honoured or refused, never degraded: an empty payload on
 * `posts_search` would drop the search condition and match every published
 * post.
 *
 * @internal
 */
final class IndexedSearchSwapTest extends TestCase
{
    public function testTheArgsNameTheIndexTheClauseIsBuiltFor(): void
    {
        $swap = $this->swap(present: true);

        $args = $swap->args(SearchTerms::fromString('harbor lights'));

        self::assertSame('harbor lights', $args['s'] ?? null, 'core\'s own search var always travels.');
        self::assertTrue($args[IndexedSearchSwap::INDEXED] ?? false, 'the declaration is the flag both filters read.');
        self::assertArrayHasKey(IndexedSearchSwap::CLAUSE, $args);
        self::assertArrayHasKey(IndexedSearchSwap::ORDERBY, $args);
    }

    public function testTheDeclaredClauseAndOrderingAreThePreparedFragments(): void
    {
        $preparer = new RecordingPreparer();
        $swap = $this->swap(present: true, preparer: $preparer);

        $args = $swap->args(SearchTerms::fromString('harbor'));

        self::assertSame(' AND ('.((string) ($preparer->statements[0] ?? '')).')', $args[IndexedSearchSwap::CLAUSE] ?? null);
        self::assertSame(((string) ($preparer->statements[1] ?? '')).' DESC', $args[IndexedSearchSwap::ORDERBY] ?? null);
        self::assertCount(2, $preparer->statements, 'the clause and the ordering are one expression, rendered twice.');
    }

    public function testWithTheIndexAbsentTheArgsAreCoresQueryAndNothingElse(): void
    {
        $swap = $this->swap(present: false);

        $args = $swap->args(SearchTerms::fromString('harbor'));

        self::assertSame(['s' => 'harbor'], $args, 'the LIKE path is core\'s query, unchanged; no flag, so both filters return their first argument.');
    }

    public function testATermWithNoUsableTokenIsRefusedOnBothPaths(): void
    {
        foreach ([true, false] as $present) {
            $swap = $this->swap(present: $present);

            try {
                $swap->args(SearchTerms::fromString('ab'));
                self::fail('a term the index cannot answer with must be refused, not scanned.');
            } catch (UnusableSearchTerm $failure) {
                self::assertSame('ab', $failure->term(), $present ? 'the indexed path refuses it.' : 'and so does core\'s LIKE path.');
            }
        }
    }

    public function testTheAbsenceIsRecordedLoudlyOncePerRequest(): void
    {
        $diagnostics = $this->diagnostics();
        $swap = $this->swap(present: false, diagnostics: $diagnostics);

        $swap->args(SearchTerms::fromString('harbor'));
        $swap->args(SearchTerms::fromString('canyon'));
        $swap->args(SearchTerms::fromString('glacier'));

        $records = $diagnostics->records();

        self::assertCount(1, $records, 'a cost that grows with traffic is not a report; one per request is.');
        self::assertSame(MatchClause::INDEX_NAME, $records[0]->context['index'] ?? null, 'the report names the index that is missing.');
    }

    public function testAQueryThatDidNotDeclareTheIndexKeepsItsOwnFragmentAndOrdering(): void
    {
        $swap = $this->swap(present: true);
        $query = new \WP_Query();
        $query->set(IndexedSearchSwap::CLAUSE, ' AND (MATCH (post_title) AGAINST (x))');

        self::assertSame(" AND (LIKE '%harbor%')", $swap->search(" AND (LIKE '%harbor%')", $query));
        self::assertSame('post_date DESC', $swap->orderby('post_date DESC', $query));
    }

    public function testADeclaredQueryIsSwappedIntoBothFilters(): void
    {
        $swap = $this->swap(present: true);
        $query = new \WP_Query();
        $query->set(IndexedSearchSwap::INDEXED, true);
        $query->set(IndexedSearchSwap::CLAUSE, ' AND (MATCH (post_title, post_excerpt, post_content) AGAINST (\'harbor\' IN NATURAL LANGUAGE MODE))');
        $query->set(IndexedSearchSwap::ORDERBY, 'MATCH (post_title, post_excerpt, post_content) AGAINST (\'harbor\' IN NATURAL LANGUAGE MODE) DESC');

        self::assertStringContainsString('MATCH (post_title, post_excerpt, post_content)', $swap->search(" AND (LIKE '%harbor%')", $query));
        self::assertStringEndsWith('DESC', $swap->orderby('post_date DESC', $query));
        self::assertStringNotContainsString('LIKE', $swap->search(" AND (LIKE '%harbor%')", $query), 'core\'s fragment is replaced, not supplemented.');
    }

    public function testADeclarationWithoutItsPayloadIsRefused(): void
    {
        $swap = $this->swap(present: true);
        $query = new \WP_Query();
        $query->set(IndexedSearchSwap::INDEXED, true);

        $this->expectException(InvalidSearchDeclaration::class);

        $swap->search(" AND (LIKE '%harbor%')", $query);
    }

    public function testADeclarationCarryingAnEmptyPayloadIsRefused(): void
    {
        $swap = $this->swap(present: true);
        $query = new \WP_Query();
        $query->set(IndexedSearchSwap::INDEXED, true);
        $query->set(IndexedSearchSwap::CLAUSE, '');

        $this->expectException(InvalidSearchDeclaration::class);

        $swap->search(" AND (LIKE '%harbor%')", $query);
    }

    public function testADeclarationCarryingAValueThatIsNotAStringIsRefused(): void
    {
        $swap = $this->swap(present: true);
        $query = new \WP_Query();
        $query->set(IndexedSearchSwap::INDEXED, true);
        $query->set(IndexedSearchSwap::ORDERBY, ['post_date']);

        $this->expectException(InvalidSearchDeclaration::class);

        $swap->orderby('post_date DESC', $query);
    }

    public function testTheRefusalNamesTheQueryVarThatFailed(): void
    {
        $swap = $this->swap(present: true);
        $query = new \WP_Query();
        $query->set(IndexedSearchSwap::INDEXED, true);

        try {
            $swap->orderby('post_date DESC', $query);
            self::fail('an absent ordering payload must be refused.');
        } catch (InvalidSearchDeclaration $failure) {
            self::assertSame(IndexedSearchSwap::ORDERBY, $failure->queryVar());
            self::assertSame('absent', $failure->found());
        }
    }

    public function testTheFlagIsReadStrictly(): void
    {
        $swap = $this->swap(present: true);

        foreach (['1', 1, 'true', null, false] as $value) {
            $query = new \WP_Query();
            $query->set(IndexedSearchSwap::INDEXED, $value);
            $query->set(IndexedSearchSwap::CLAUSE, ' AND (MATCH (post_title) AGAINST (x))');

            self::assertSame(
                " AND (LIKE '%harbor%')",
                $swap->search(" AND (LIKE '%harbor%')", $query),
                'only a true flag declares the path; a value a request could have invented never does.',
            );
        }
    }

    private function swap(
        bool $present,
        ?Diagnostics $diagnostics = null,
        ?RecordingPreparer $preparer = null,
    ): IndexedSearchSwap {
        return new IndexedSearchSwap(
            clause: new MatchClause($preparer ?? new RecordingPreparer(), SearchIndex::onPosts('wp_')),
            fallback: new SearchFallbackReport(
                presence: new StubIndexPresence($present),
                diagnostics: $diagnostics ?? $this->diagnostics(),
            ),
        );
    }
}
