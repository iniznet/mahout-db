<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Exception\InvalidRow;
use Iniznet\Mahout\Db\Exception\UnboundedStatement;

/**
 * The predicate of a gateway statement, bounded by construction.
 *
 * There is no constructor that produces a statement with neither a LIMIT nor a
 * primary-key equality. STO-22's rule is a compile-time floor that cannot see a
 * table name reaching a statement as a runtime value; this type is the runtime
 * floor that makes that statement unrepresentable.
 *
 * A set predicate is the third bounded shape: the declared equalities AND one
 * declared column among a non-empty list of values, with a LIMIT that caps the
 * whole page rather than one row. It exists so a cache prime can be written as one
 * statement — the set is the caller's already-bounded id list, which is the same
 * shape as core's `update_meta_cache()`, and the LIMIT bounds it again.
 */
final readonly class GatewayQuery
{
    /**
     * @param list<int|string> $setValues every value validated by self::among()
     */
    private function __construct(
        public Row $conditions,
        public ?int $limit,
        public ?Column $setColumn = null,
        public array $setValues = [],
    ) {
    }

    /**
     * An equality on a non-empty leading prefix of the declared primary key.
     */
    public static function keyed(Row $key): self
    {
        if (!self::isLeadingPrefix($key)) {
            throw UnboundedStatement::forTable($key->table->name->value);
        }

        return new self($key, null);
    }

    /**
     * An arbitrary equality predicate with a declared LIMIT.
     */
    public static function bounded(Row $conditions, int $limit): self
    {
        if ($limit < 1) {
            throw UnboundedStatement::forLimit($conditions->table->name->value, $limit);
        }

        return new self($conditions, $limit);
    }

    /**
     * Every row of the table up to a declared LIMIT.
     */
    public static function all(Table $table, int $limit): self
    {
        return self::bounded(Row::of($table, []), $limit);
    }

    /**
     * The declared equalities, plus one declared column among a non-empty set of
     * values, capped by a LIMIT over the whole result.
     *
     * The column is a declared `Column`, never a name, because ADR-0011 allows no
     * string identifier into a statement. An empty set is refused: read as an
     * omitted clause it would silently mean "every row", which is the one shape
     * this type exists to make unrepresentable.
     *
     * @param array<array-key, mixed> $values the set, validated to a non-empty list of scalars here
     */
    public static function among(Row $conditions, Column $column, array $values, int $limit): self
    {
        $table = $conditions->table->name->value;

        if ($limit < 1) {
            throw UnboundedStatement::forLimit($table, $limit);
        }

        if ([] === $values) {
            throw InvalidRow::emptySet($table);
        }

        if (!$conditions->table->hasColumn($column->name->value)) {
            throw InvalidRow::foreignColumn($table, $column->name->value);
        }

        $set = [];

        foreach ($values as $value) {
            if (!\is_int($value) && !\is_string($value)) {
                throw InvalidRow::nonScalarSet($table);
            }

            $set[] = $value;
        }

        return new self($conditions, $limit, $column, $set);
    }

    private static function isLeadingPrefix(Row $key): bool
    {
        $primary = $key->table->primaryKeyColumns();
        if ([] === $primary) {
            return false;
        }

        $given = [];
        foreach (\array_keys($key->values()) as $name) {
            $given[] = (string) $name;
        }

        if ([] === $given || \count($given) > \count($primary)) {
            return false;
        }

        $prefix = \array_slice($primary, 0, \count($given));
        \sort($given);
        \sort($prefix);

        return $given === $prefix;
    }
}
