<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Unit;

use Iniznet\Mahout\Db\Exception\UnusableSearchTerm;
use Iniznet\Mahout\Db\Search\MatchClause;
use Iniznet\Mahout\Db\Search\SearchTerms;
use Iniznet\Mahout\Db\SearchIndex;
use Iniznet\Mahout\Db\Tests\Fixtures\RecordingPreparer;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The clause is built from the index declaration and nothing else, and the
 * visitor's term never enters the statement text.
 *
 * Error 1191 -- "Can't find FULLTEXT index matching the column list" -- is the
 * failure this shape prevents: MATCH must name the index's columns exactly, in
 * the index's order, so the list is read from the declaration rather than
 * retyped at the call site.
 *
 * @internal
 */
final class MatchClauseTest extends TestCase
{
    public function testTheColumnsComeFromTheIndexDeclaration(): void
    {
        $clause = $this->clause(new RecordingPreparer());

        self::assertSame(
            SearchIndex::onPosts('wp_')->columns(),
            $clause->columns(),
            'the clause names the declared columns, in the declared order.',
        );
        self::assertSame(['post_title', 'post_excerpt', 'post_content'], $clause->columns());
    }

    public function testTheStatementNamesEveryIndexedColumnOnce(): void
    {
        $preparer = new RecordingPreparer();
        $clause = $this->clause($preparer);

        $clause->against(SearchTerms::fromString('beta'));

        $statement = $preparer->statements[0] ?? '';

        self::assertSame(
            'MATCH (post_title, post_excerpt, post_content) AGAINST (%s IN NATURAL LANGUAGE MODE)',
            $statement,
            'the natural-language shape, over exactly the indexed columns.',
        );

        foreach (SearchIndex::onPosts('wp_')->columns() as $column) {
            self::assertSame(1, \substr_count($statement, $column), $column.' appears exactly once.');
        }
    }

    public function testTheTermTravelsAsAValueAndNeverInTheStatementText(): void
    {
        $preparer = new RecordingPreparer();
        $clause = $this->clause($preparer);

        $clause->against(SearchTerms::fromString('harbor canyon'));

        self::assertSame(['harbor canyon'], $preparer->values[0] ?? [], 'the joined tokens are a placeholder value.');
        self::assertStringNotContainsString('harbor', $preparer->statements[0] ?? '', 'no part of the term is concatenated into the statement.');
    }

    public function testTheFragmentCarriesCoresOwnAndAndParenthesisation(): void
    {
        $clause = $this->clause(new RecordingPreparer());

        $fragment = $clause->fragment(SearchTerms::fromString('beta'));

        self::assertStringStartsWith(' AND (', $fragment, 'core glues the payload after "WHERE 1=1", so the fragment is its own AND.');
        self::assertStringEndsWith(')', $fragment, 'and it is parenthesised, as core\'s LIKE fragment is.');
        self::assertStringNotContainsString(' DESC', $fragment, 'the WHERE fragment carries no ordering.');
    }

    public function testTheRelevanceIsTheSameExpressionDescending(): void
    {
        $preparer = new RecordingPreparer();
        $clause = $this->clause($preparer);

        $ordering = $clause->relevance(SearchTerms::fromString('beta'));
        $clause->against(SearchTerms::fromString('beta'));

        self::assertSame(((string) ($preparer->statements[0] ?? '')).' DESC', $ordering, 'relevance is the index score, never a LIKE.');
        self::assertStringNotContainsString('LIKE', $ordering);
    }

    public function testATermWithNoUsableTokenIsRefusedBeforeAnyStatement(): void
    {
        $preparer = new RecordingPreparer();
        $clause = $this->clause($preparer);

        $this->expectException(UnusableSearchTerm::class);

        try {
            $clause->against(SearchTerms::fromString('ab'));
        } finally {
            self::assertSame([], $preparer->statements, 'nothing was rendered, so nothing can reach the engine.');
        }
    }

    public function testTheNamedConstructorReadsTheConnectionAndTheTableItRunsOn(): void
    {
        $clause = MatchClause::fromWordPress();

        self::assertSame($this->posts(), $clause->table()->value, 'the clause runs on core\'s posts table, prefixed.');
        self::assertSame(MatchClause::INDEX_NAME, SearchIndex::NAME, 'one name, declared once.');
    }

    private function clause(RecordingPreparer $preparer): MatchClause
    {
        return new MatchClause($preparer, SearchIndex::onPosts('wp_'));
    }

    private function posts(): string
    {
        global $wpdb;

        return (string) $wpdb->posts;
    }
}
