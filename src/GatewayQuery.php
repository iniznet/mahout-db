<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Exception\UnboundedStatement;

/**
 * The predicate of a gateway statement, bounded by construction.
 *
 * There is no constructor that produces a statement with neither a LIMIT nor a
 * primary-key equality. STO-22's rule is a compile-time floor that cannot see a
 * table name reaching a statement as a runtime value; this type is the runtime
 * floor that makes that statement unrepresentable.
 */
final readonly class GatewayQuery
{
    private function __construct(
        public Row $conditions,
        public ?int $limit,
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
