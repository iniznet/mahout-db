<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Exception\InvalidIndex;
use Iniznet\Mahout\Db\Exception\InvalidTable;

/**
 * A declared table: its name (prefix already applied), its columns, its indexes,
 * its engine and its charset/collation.
 *
 * The engine has no default. A table that does not state InnoDB inherits the
 * server's default_storage_engine, which the emitter then refuses. The
 * charset/collation comes from \$wpdb->get_charset_collate() at the declaration
 * site and is carried verbatim, because that call supplies the charset and the
 * collation and never the engine.
 */
final readonly class Table
{
    /**
     * @param list<Column> $columns
     * @param list<Index>  $indexes
     */
    public function __construct(
        public Identifier $name,
        public array $columns,
        public array $indexes,
        public Engine $engine,
        public string $charsetCollate,
    ) {
        $this->assertDeclared();
    }

    private function assertDeclared(): void
    {
        if ([] === $this->columns) {
            throw InvalidTable::noColumns($this->name->value);
        }

        $columns = [];
        foreach ($this->columns as $column) {
            if (\array_key_exists($column->name->value, $columns)) {
                throw InvalidTable::duplicateColumn($this->name->value, $column->name->value);
            }
            $columns[$column->name->value] = $column;
        }

        $indexes = [];
        $primaries = 0;
        $covered = [];
        foreach ($this->indexes as $index) {
            if (\array_key_exists($index->name->value, $indexes)) {
                throw InvalidTable::duplicateIndex($this->name->value, $index->name->value);
            }
            $indexes[$index->name->value] = $index;

            if (IndexKind::Primary === $index->kind) {
                ++$primaries;
            }

            foreach ($index->columnNames() as $column) {
                if (!\array_key_exists($column, $columns)) {
                    throw InvalidIndex::unknownColumn($index->name->value, $column);
                }
                $covered[$column] = true;
            }
        }

        if ($primaries > 1) {
            throw InvalidTable::duplicatePrimaryKey($this->name->value);
        }

        foreach ($this->columns as $column) {
            if ($column->autoIncrement && !\array_key_exists($column->name->value, $covered)) {
                throw InvalidTable::autoIncrementWithoutKey($this->name->value, $column->name->value);
            }
        }
    }
}
