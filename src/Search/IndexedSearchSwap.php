<?php

/**
 * The indexed search swap: the declaration a search query carries, and the two
 * core filters that honour it.
 *
 * A search runs on the index when the index exists, and on core's own LIKE
 * query when it does not. Which of the two it is, and the exact shape of the
 * fragments that travel between the query and the filters, belong here rather
 * than in a consumer: the ` AND (` a `posts_search` payload needs is core's
 * grammar, and a consumer that retypes it gets a different query.
 *
 * With the index absent the args are core's search, unchanged, and the absence
 * is recorded once per request -- it is a fact a site must see, and a log line
 * per query would be a cost that grows with traffic.
 *
 * The swap resolves no collaborator and attaches nothing: {@see SearchProvider}
 * builds it and attaches the two filters it exposes.
 */

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Search;

use Iniznet\Mahout\Db\Exception\InvalidSearchDeclaration;
use Iniznet\Mahout\Db\Exception\UnusableSearchTerm;

final readonly class IndexedSearchSwap
{
    /** The query var that declares a search runs on the index. */
    public const string INDEXED = 'mahout_indexed_search';

    /** The query var carrying the `posts_search` payload. */
    public const string CLAUSE = 'mahout_match_clause';

    /** The query var carrying the `posts_search_orderby` payload. */
    public const string ORDERBY = 'mahout_match_orderby';

    public function __construct(
        private MatchClause $clause,
        private SearchFallbackReport $fallback,
    ) {
    }

    /**
     * Whether this site's search travels the index.
     *
     * The read is one cached option and issues no statement; a consumer that
     * only wants to know asks here, and nothing is recorded.
     */
    public function isIndexed(): bool
    {
        return $this->fallback->isIndexed();
    }

    /**
     * The args one tokenised term turns into a search query.
     *
     * `s` is always core's own, because the LIKE path is core's query. The
     * three declared vars appear only when the index is present, and when they
     * do, the two filters below are what make them mean anything.
     *
     * A term with no usable token is refused on both paths. The fallback is
     * core's query, not a scan of a term the index cannot answer with: on the
     * LIKE path an untokenised term is a leading-wildcard match over the whole
     * posts table, which is the cost this contract exists to prevent.
     *
     * @return array<string, string|bool>
     */
    public function args(SearchTerms $terms): array
    {
        if (!$terms->hasTokens()) {
            throw UnusableSearchTerm::withoutTokens($terms->raw);
        }

        if (!$this->isIndexed()) {
            $this->fallback->reportOnce();

            return ['s' => $terms->raw];
        }

        return [
            's' => $terms->raw,
            self::INDEXED => true,
            self::CLAUSE => $this->clause->fragment($terms),
            self::ORDERBY => $this->clause->relevance($terms),
        ];
    }

    /**
     * The `posts_search` filter: core's fragment for every other query, the
     * declared clause for a search that declared the index.
     */
    public function search(string $fragment, \WP_Query $query): string
    {
        if (!$this->declaresIndexed($query)) {
            return $fragment;
        }

        return $this->payload($query, self::CLAUSE);
    }

    /**
     * The `posts_search_orderby` filter, in the same shape: the ordering that
     * query already had, or the index's relevance score for a declared search.
     */
    public function orderby(string $ordering, \WP_Query $query): string
    {
        if (!$this->declaresIndexed($query)) {
            return $ordering;
        }

        return $this->payload($query, self::ORDERBY);
    }

    /**
     * Whether this query declared the indexed path.
     *
     * The flag is only ever set by {@see args()}, which runs in this process.
     * Neither var is a public query var, so a remote request cannot set the
     * flag and force the swap to read a clause no local code wrote.
     */
    public function declaresIndexed(\WP_Query $query): bool
    {
        return true === $query->get(self::INDEXED);
    }

    /**
     * A declaration is honoured or refused, never degraded. A declared search
     * whose payload is absent, empty or not a string would otherwise swap in an
     * empty fragment -- which for `posts_search` means no search condition at
     * all, and every published post matching.
     */
    private function payload(\WP_Query $query, string $var): string
    {
        $payload = $query->get($var, null);

        if (null === $payload) {
            throw InvalidSearchDeclaration::absent($var);
        }

        if (!\is_string($payload)) {
            throw InvalidSearchDeclaration::notAString($var, get_debug_type($payload));
        }

        if ('' === $payload) {
            throw InvalidSearchDeclaration::empty($var);
        }

        return $payload;
    }
}
