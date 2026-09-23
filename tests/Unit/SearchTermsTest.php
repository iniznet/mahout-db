<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Unit;

use Iniznet\Mahout\Db\Search\SearchTerms;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The tokeniser is the gate between a visitor's string and the FULLTEXT
 * engine: only word runs survive, so no boolean operator, quote, wildcard or
 * punctuation can reach AGAINST. The caps are enforced before any query is
 * built.
 *
 * @internal
 */
final class SearchTermsTest extends TestCase
{
    public function testTheTermIsSplitIntoWordRunsOnly(): void
    {
        $terms = SearchTerms::fromString('beta ++gamma "quoted phrase" *star* (paren)');

        self::assertSame(['beta', 'gamma', 'quoted', 'phrase', 'star', 'paren'], $terms->tokens(), 'only word runs survive the tokeniser.');
    }

    public function testAMalformedBooleanStringNeverProducesOperators(): void
    {
        foreach (['a OR 1=1', '+beta* OR -gamma', 'beta~ AND (gamma)', 'beta* gamma~'] as $term) {
            foreach (SearchTerms::fromString($term)->tokens() as $token) {
                self::assertDoesNotMatchRegularExpression('/[+\-*()~<>"]/', $token, 'no boolean syntax survives: '.$token);
            }
        }
    }

    public function testTheTokenCountIsCapped(): void
    {
        $terms = SearchTerms::fromString('one two three four five six seven eight nine ten');

        self::assertCount(SearchTerms::MAX_TOKENS, $terms->tokens(), 'at most eight tokens travel.');
    }

    public function testAnOverlongTokenIsDropped(): void
    {
        $long = \str_repeat('x', SearchTerms::MAX_TOKEN_BYTES + 1);
        $terms = SearchTerms::fromString($long.' beta');

        self::assertSame(['beta'], $terms->tokens(), 'a token beyond the byte cap never reaches the query.');
    }

    public function testTokensShorterThanTheIndexMinimumLeaveNoTokens(): void
    {
        $terms = SearchTerms::fromString('ab cd -- **');

        self::assertFalse($terms->hasTokens(), 'every token is shorter than the index minimum; the caller renders its empty state.');
        self::assertSame('', $terms->forMatch(), 'no clause is built from an unusable term.');
    }

    public function testUnicodeWordsSurviveTheSplit(): void
    {
        $terms = SearchTerms::fromString('Übermensch καλημέρα');

        self::assertSame(['Übermensch', 'καλημέρα'], $terms->tokens(), 'letters outside ASCII are ordinary words.');
    }

    public function testTheMatchPayloadIsThePlainTokenJoin(): void
    {
        $terms = SearchTerms::fromString('beta gamma');

        self::assertSame('beta gamma', $terms->forMatch(), 'natural language mode receives plain words, one string.');
    }

    public function testTheRawTermIsKeptForTheCallersOwnUse(): void
    {
        $terms = SearchTerms::fromString('  beta ++gamma  ');

        self::assertSame('  beta ++gamma  ', $terms->raw, 'the raw term is what a caller echoes back; the tokens are what it queries.');
    }
}
