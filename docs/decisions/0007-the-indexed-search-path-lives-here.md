# ADR-0007 — The indexed search path lives in `mahout-db`

Status: accepted.

## Context

The theme carried the machinery that makes a search run on the `FULLTEXT` index
`mahout-db` creates: a tokeniser, a `MATCH (…) AGAINST (…)` clause, and two core
filters that swap both into a `WP_Query`. Splitting the pair across a package
boundary left three facts owned by the wrong party.

1. The clause's column list has to equal the index's column list exactly, or the
   server raises error 1191. The index is declared in `SearchIndex`; the column
   list was retyped in the theme's `MatchClause`. Two lists that must agree and
   are maintained in two repositories is a drift bug waiting to be scheduled.
2. The `AND` and the parenthesisation a `posts_search` payload carries are core's
   grammar, not the caller's. The theme wrote them; every consumer would have had
   to write them too.
3. The swap is only sound together with the presence check that gates it, and
   presence is already this package's (`Contracts\SearchIndexPresence`, refreshed
   from `mahout/db/after_migrate`).

The theme also owned the index name as its own string, and named the query vars
after itself.

## Decision

- `Search\SearchTerms` is the tokeniser: word runs only, `MAX_TOKENS` 8,
  `MAX_TOKEN_BYTES` 100, `MIN_TOKEN_SIZE` 3, with `hasTokens()` and `forMatch()`.
  Ported verbatim; the caps are the contract a visitor's string is held to before
  any query exists.
- `Search\MatchClause` renders the `MATCH` expression through
  `Contracts\StatementPreparer` and reads its columns from `SearchIndex`, so the
  clause and the index are one declaration. `fragment()` and `relevance()` own
  core's payload shapes. `SearchIndex::NAME` is public, and the clause's index
  name is that constant — the string appears once in the package.
- `Search\IndexedSearchSwap` owns the two core filters and the args that make the
  declaration, under package-owned query vars: `mahout_indexed_search`,
  `mahout_match_clause`, `mahout_match_orderby`. A query opts in through
  `args()`; a query that did not declare the path gets its own argument back.
- `Search\SearchFallbackReport` records an absent index once per request, through
  the kernel's `Diagnostics`.
- `Search\SearchProvider` builds the three and attaches the two filters.
  `hooks:generate` records `posts_search` and `posts_search_orderby` as filters
  this package observes.
- A declared search whose payload is absent, empty or not a string throws
  `Exception\InvalidSearchDeclaration` rather than swapping in an empty fragment:
  for `posts_search` an empty fragment means no search condition and every
  published post.
- The rule the theme had is preserved exactly: with the index absent the args are
  core's own search and the LIKE path runs unchanged, and the absence is reported
  loudly.
- `Contracts\StatementPreparer` is new. `SqlConnection` runs a statement; a search
  fragment belongs to another party's query and must be rendered without being
  run. `Internal\WpdbConnection` implements both, because both are the same
  connection, and it remains the only class that names `$wpdb`.

## Consequences

- A consumer that wants an indexed search depends on one package: the declaration
  that creates the index, the clause that names it, and the filters that swap it.
- The theme keeps the decision of *which* fields a search covers by post type and
  the presentation of an empty state; it no longer knows SQL. Its rewiring is a
  follow-up change, not part of this one.
- `MatchClause::fromWordPress()` is the second documented place that names
  `WpdbConnection`, beside `DbProvider::register()`, for the reason ADR-0004
  records: a composition root that is built by class name must read the one global
  WordPress publishes. `ADR-0004` is corrected to say so.
- The index name is still `howdah_search`. Renaming it is a schema change, and a
  schema change is a migration; nothing in this move requires one.
