<?php

/**
 * The FULLTEXT clause and its relevance ordering, built for the index this
 * package declares.
 *
 * The column list is read from the index declaration and never retyped: MATCH
 * must name the index's columns exactly, or the server raises error 1191. That
 * is why this class takes the {@see SearchIndex} instead of a list of columns,
 * and why presence is checked against the same declaration the clause is built
 * from -- the two can never disagree.
 *
 * Only a tokenised term reaches a clause. The word runs the tokeniser kept are
 * plain text with no operator, quote or wildcard in them, and the value still
 * travels through a placeholder, into natural language mode: boolean mode is
 * the one that raises SQL error 1064 on a malformed string.
 */

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Search;

use Iniznet\Mahout\Db\Contracts\StatementPreparer;
use Iniznet\Mahout\Db\Exception\UnusableSearchTerm;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Internal\WpdbConnection;
use Iniznet\Mahout\Db\SearchIndex;

final readonly class MatchClause
{
    /** The index the clause is built for, named by its own declaration. */
    public const string INDEX_NAME = SearchIndex::NAME;

    public function __construct(
        private StatementPreparer $preparer,
        private SearchIndex $index,
    ) {
    }

    /**
     * The composition-root named constructor: the connection object, read
     * once, inside one boundary.
     *
     * It resolves no collaborator. The index it names is the declaration the
     * package's own migration creates, so the clause and the presence check
     * read one column list rather than two that could drift apart.
     */
    public static function fromWordPress(): self
    {
        $connection = WpdbConnection::inWordPress();

        return new self($connection, SearchIndex::onPosts($connection->prefix()));
    }

    /** The table the MATCH runs on, from the index declaration. */
    public function table(): Identifier
    {
        return $this->index->table();
    }

    /**
     * The index's column list, from the index declaration.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->index->columns();
    }

    /**
     * MATCH (… ) AGAINST (? IN NATURAL LANGUAGE MODE), with the term quoted
     * and escaped by the connection and nothing executed.
     */
    public function against(SearchTerms $terms): string
    {
        $joined = $terms->forMatch();

        if ('' === $joined) {
            throw UnusableSearchTerm::withoutTokens($terms->raw);
        }

        $statement = 'MATCH ('.\implode(', ', $this->index->columns()).') AGAINST (%s IN NATURAL LANGUAGE MODE)';

        return $this->preparer->prepare($statement, $joined);
    }

    /**
     * The payload `posts_search` receives.
     *
     * Core glues the fragment straight after `WHERE 1=1`, so the swapped-in
     * text carries core's own `AND` and the parenthesisation its LIKE fragment
     * has. That shape is core's grammar, not the caller's, and it is written
     * once, here.
     */
    public function fragment(SearchTerms $terms): string
    {
        return ' AND ('.$this->against($terms).')';
    }

    /** The payload `posts_search_orderby` receives: the index's score, descending. */
    public function relevance(SearchTerms $terms): string
    {
        return $this->against($terms).' DESC';
    }
}
