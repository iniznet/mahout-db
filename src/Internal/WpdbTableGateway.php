<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Internal;

use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Db\Exception\InvalidRow;
use Iniznet\Mahout\Db\GatewayQuery;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Table;

/**
 * The typed table gateway against one SqlConnection.
 *
 * It is the only class in the package that issues START TRANSACTION, COMMIT or
 * ROLLBACK, and it is the only class that builds a data statement. Every
 * identifier in a statement is read back from the declared Table; every value
 * is bound through a placeholder; a null is emitted as the SQL literal NULL.
 *
 * @internal
 */
final class WpdbTableGateway implements TableGateway
{
    private int $depth = 0;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function transactional(\Closure $work): void
    {
        if ($this->depth > 0) {
            ++$this->depth;

            try {
                $work();
            } finally {
                --$this->depth;
            }

            return;
        }

        $this->connection->execute('START TRANSACTION');
        $this->depth = 1;

        try {
            $work();
            $this->connection->execute('COMMIT');
        } catch (\Throwable $failure) {
            $this->connection->execute('ROLLBACK');

            throw $failure;
        } finally {
            $this->depth = 0;
        }
    }

    public function select(GatewayQuery $query): array
    {
        $table = $query->conditions->table;
        [$where, $values] = $this->predicate($query->conditions);

        $statement = 'SELECT '.$this->selectList($table)
            .' FROM '.$table->name->quoted()
            .$this->where($where)
            .$this->limit($query->limit);

        return $this->collect($table, $statement, $values);
    }

    public function insert(Row $row): void
    {
        $this->write(
            'INSERT INTO '.$row->table->name->quoted()
                .' ('.$this->quotedColumns($row).') VALUES ('.$this->placeholders($row).')',
            $this->boundValues($row),
        );
    }

    public function upsert(Row $row): void
    {
        $this->write(
            'INSERT INTO '.$row->table->name->quoted()
                .' ('.$this->quotedColumns($row).') VALUES ('.$this->placeholders($row).')'
                .$this->duplicateClause($row),
            $this->boundValues($row),
        );
    }

    public function update(Row $values, GatewayQuery $query): void
    {
        $this->assertTable($values, $query->conditions->table);
        [$assignments, $setValues] = $this->assignments($values);
        [$where, $whereValues] = $this->predicate($query->conditions);

        $this->write(
            'UPDATE '.$values->table->name->quoted().' SET '.$assignments
                .$this->where($where).$this->limit($query->limit),
            [...$setValues, ...$whereValues],
        );
    }

    public function delete(GatewayQuery $query): void
    {
        [$where, $values] = $this->predicate($query->conditions);

        $this->write(
            'DELETE FROM '.$query->conditions->table->name->quoted()
                .$this->where($where).$this->limit($query->limit),
            $values,
        );
    }

    public function deleteMany(array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $table = $rows[0]->table;
        $primary = $this->primaryKey($table);
        $columns = $this->quotedPrimary($table, $primary);

        $tuples = [];
        $values = [];
        foreach ($rows as $row) {
            $this->assertTable($row, $table);
            $placeholders = [];
            foreach ($primary as $name) {
                $value = $this->requiredValue($row, $name, $table);
                $placeholders[] = \is_int($value) ? '%d' : '%s';
                $values[] = $value;
            }

            $tuples[] = '('.\implode(', ', $placeholders).')';
        }

        $this->write(
            'DELETE FROM '.$table->name->quoted()
                .' WHERE ('.\implode(', ', $columns).') IN ('.\implode(', ', $tuples).')',
            $values,
        );
    }

    public function chunk(Table $table, ?Row $after, int $limit): array
    {
        if ($limit < 1) {
            throw InvalidRow::nonPositiveLimit($limit);
        }

        $primary = $this->primaryKey($table);
        $columns = $this->quotedPrimary($table, $primary);
        $statement = 'SELECT '.$this->selectList($table).' FROM '.$table->name->quoted();
        $values = [];

        if (null !== $after) {
            $this->assertTable($after, $table);
            $placeholders = [];
            foreach ($primary as $name) {
                $value = $this->requiredValue($after, $name, $table);
                $placeholders[] = \is_int($value) ? '%d' : '%s';
                $values[] = $value;
            }

            $statement .= ' WHERE ('.\implode(', ', $columns).') > ('.\implode(', ', $placeholders).')';
        }

        $statement .= ' ORDER BY '.\implode(', ', $columns).' LIMIT '.$limit;

        return $this->collect($table, $statement, $values);
    }

    /**
     * @return array{string, list<string|int>}
     */
    private function predicate(Row $conditions): array
    {
        $parts = [];
        $values = [];
        foreach ($conditions->values() as $name => $value) {
            $column = $conditions->table->column((string) $name);
            if (null === $value) {
                $parts[] = $column->name->quoted().' IS NULL';

                continue;
            }

            $parts[] = $column->name->quoted().' = '.(\is_int($value) ? '%d' : '%s');
            $values[] = $value;
        }

        return [\implode(' AND ', $parts), $values];
    }

    /**
     * @return array{string, list<string|int>}
     */
    private function assignments(Row $values): array
    {
        $parts = [];
        $bound = [];
        foreach ($values->values() as $name => $value) {
            $column = $values->table->column((string) $name);
            if (null === $value) {
                $parts[] = $column->name->quoted().' = NULL';

                continue;
            }

            $parts[] = $column->name->quoted().' = '.(\is_int($value) ? '%d' : '%s');
            $bound[] = $value;
        }

        if ([] === $parts) {
            throw InvalidRow::emptyValues($values->table->name->value);
        }

        return [\implode(', ', $parts), $bound];
    }

    private function duplicateClause(Row $row): string
    {
        $primary = $row->table->primaryKeyColumns();
        $updates = [];
        foreach ($row->values() as $name => $value) {
            $column = $row->table->column((string) $name);
            if (\in_array($column->name->value, $primary, true)) {
                continue;
            }

            $updates[] = $column->name->quoted().' = VALUES('.$column->name->quoted().')';
        }

        return [] === $updates ? '' : ' ON DUPLICATE KEY UPDATE '.\implode(', ', $updates);
    }

    private function quotedColumns(Row $row): string
    {
        $columns = [];
        foreach (\array_keys($row->values()) as $name) {
            $columns[] = $row->table->column((string) $name)->name->quoted();
        }

        return \implode(', ', $columns);
    }

    private function placeholders(Row $row): string
    {
        $placeholders = [];
        foreach ($row->values() as $value) {
            if (null === $value) {
                $placeholders[] = 'NULL';

                continue;
            }

            $placeholders[] = \is_int($value) ? '%d' : '%s';
        }

        return \implode(', ', $placeholders);
    }

    /**
     * @return list<string|int>
     */
    private function boundValues(Row $row): array
    {
        $values = [];
        foreach ($row->values() as $value) {
            if (null === $value) {
                continue;
            }

            $values[] = $value;
        }

        return $values;
    }

    private function selectList(Table $table): string
    {
        $columns = [];
        foreach ($table->columns as $column) {
            $columns[] = $column->name->quoted();
        }

        return \implode(', ', $columns);
    }

    /**
     * @return list<string>
     */
    private function primaryKey(Table $table): array
    {
        $primary = $table->primaryKeyColumns();
        if ([] === $primary) {
            throw InvalidRow::missingPrimaryKey($table->name->value);
        }

        return $primary;
    }

    /**
     * @param list<string> $primary
     *
     * @return list<string>
     */
    private function quotedPrimary(Table $table, array $primary): array
    {
        $columns = [];
        foreach ($primary as $name) {
            $columns[] = $table->column($name)->name->quoted();
        }

        return $columns;
    }

    private function requiredValue(Row $row, string $name, Table $table): string|int
    {
        if (!$row->has($name)) {
            throw InvalidRow::missingPrimaryKey($table->name->value);
        }

        $value = $row->value($name);
        if (null === $value) {
            throw InvalidRow::missingPrimaryKey($table->name->value);
        }

        return $value;
    }

    private function assertTable(Row $row, Table $table): void
    {
        if ($row->table->name->value !== $table->name->value) {
            throw InvalidRow::wrongTable($row->table->name->value, $table->name->value);
        }
    }

    private function where(string $where): string
    {
        return '' === $where ? '' : ' WHERE '.$where;
    }

    private function limit(?int $limit): string
    {
        return null === $limit ? '' : ' LIMIT '.$limit;
    }

    /**
     * @param list<string|int> $values
     */
    private function write(string $statement, array $values): void
    {
        if ([] === $values) {
            $this->connection->execute($statement);

            return;
        }

        $this->connection->executePrepared($statement, ...$values);
    }

    /**
     * @param list<string|int> $values
     *
     * @return list<Row>
     */
    private function collect(Table $table, string $statement, array $values): array
    {
        $result = [] === $values
            ? $this->connection->rows($statement)
            : $this->connection->rowsPrepared($statement, ...$values);

        $rows = [];
        foreach ($result as $row) {
            $rows[] = Row::of($table, $row);
        }

        return $rows;
    }
}
