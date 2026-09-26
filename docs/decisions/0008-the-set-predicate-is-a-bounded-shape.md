# ADR-0008 — The set predicate is a bounded shape

Status: accepted

## Context

The gateway spoke two shapes: a key covering a leading prefix of the primary key,
and an arbitrary equality predicate with a `LIMIT`. Both address one row or one
page of one predicate. Neither can express the statement a cache prime needs: every
row for *these* objects, in one round trip. `update_meta_cache()` gets to make that
promise about post meta because core owns its own SQL; a package with its own value
table had no equivalent, so a caller that wanted a page of rows had to ask for them
row by row.

## Decision

`GatewayQuery::among(Row $conditions, Column $column, array $values, int $limit)`:
the declared equalities, AND one declared column among a non-empty list of values,
AND a `LIMIT` capping the whole result.

Three refusals, each a distinct failure:

- a non-positive limit, because the set makes the row count the caller's, and an
  unbounded set is a scan dressed as a prime;
- a column that the conditions' table does not declare, which is the same class of
  mistake as a row from the wrong table;
- a non-scalar value, which has no placeholder to bind through.

The set itself is a value list, not a subquery, and the column is a declared
`Column` rather than a name, so the predicate shape stays in ADR-0011's terms: no
string identifier reaches a statement, no argument arrives unbound.

An empty list is refused rather than treated as "no predicate", because the
sentence that makes an empty set mean everything is the one sentence a bounded
statement type must never allow. `deleteMany()` already builds its tuple form from
a non-empty list and returns before touching the connection on an empty one; a set
*predicate* cannot make the same escape, because there is no row-level key to omit.

## Consequences

The gateway still builds every statement, so the compile-time rule keeps seeing no
SQL to pattern-match, and `WpdbTableGateway` grows one clause rather than a second
statement builder. The set is now the shape any package can prime from: fields uses
it for the value table, and a future maintainer who wants a per-page read of another
table writes the same one statement instead of discovering, in production, that the
two shapes available both cost a round trip per row.
