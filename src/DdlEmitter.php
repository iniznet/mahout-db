<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Exception\EngineNotInnoDB;

/**
 * The one place that writes DDL.
 *
 * dbDelta is not used anywhere: it is formatting-sensitive, it cannot express a
 * drop, and it hides the intent of the statement it emits. Every table names its
 * engine explicitly, so a table never inherits the server's default.
 *
 * Statement text is joined with a newline rather than PHP_EOL so the emitted DDL
 * is byte-identical on every platform, which is what makes a golden assertion
 * meaningful.
 */
final class DdlEmitter
{
    private const string NEWLINE = "\n";

    public function create(Table $table): string
    {
        return $this->createStatement($table, 'CREATE TABLE');
    }

    /**
     * The ledger's bootstrap. The ledger must exist before the first migration
     * can be recorded, so it is created idempotently ahead of the ledger itself,
     * and this is the only statement in the package that is not owned by a
     * migration.
     */
    public function createIfNotExists(Table $table): string
    {
        return $this->createStatement($table, 'CREATE TABLE IF NOT EXISTS');
    }

    public function drop(Identifier $table): string
    {
        return 'DROP TABLE IF EXISTS '.$table->quoted().';';
    }

    public function addIndex(Identifier $table, Index $index): string
    {
        return 'ALTER TABLE '.$table->quoted().' ADD '.$index->definition().';';
    }

    public function dropIndex(Identifier $table, Index $index): string
    {
        return 'ALTER TABLE '.$table->quoted().' DROP INDEX '.$index->name->quoted().';';
    }

    /**
     * A column drop. It is the shape a destructive reversal takes, and the only
     * reason it exists: a migration that drops a column cannot reconstruct its
     * values, so it declares itself irreversible instead of pretending.
     */
    public function dropColumn(Identifier $table, Column $column): string
    {
        return 'ALTER TABLE '.$table->quoted().' DROP COLUMN '.$column->name->quoted().';';
    }

    private function createStatement(Table $table, string $verb): string
    {
        if (Engine::InnoDB !== $table->engine) {
            throw EngineNotInnoDB::forTable($table->name->value, $table->engine->value);
        }

        $definitions = [];
        foreach ($table->columns as $column) {
            $definitions[] = '  '.$column->definition();
        }
        foreach ($table->indexes as $index) {
            $definitions[] = '  '.$index->definition();
        }

        return $verb.' '.$table->name->quoted().' ('.self::NEWLINE
            .\implode(','.self::NEWLINE, $definitions).self::NEWLINE
            .') ENGINE='.$table->engine->value.' '.$table->charsetCollate.';';
    }
}
