<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Kernel\RuntimeIdentity;

/**
 * The migration ledger: one row per applied migration, with its batch and the
 * timestamp it ran. The batch column is the reason --rollback can revert the
 * last batch in reverse order.
 *
 * A value factory: it resolves no collaborator, so it is a permitted static
 * call. The prefix and the host identity arrive as arguments, so a name is
 * applied where the schema is declared and never at query time.
 *
 * The identity is what makes two mahout systems on one site two ledgers. Each
 * records its own applied migrations and its own batches; a shared ledger would
 * have one host's history suppress the other's, and a rollback of one system
 * would reverse the other's schema.
 */
final readonly class MigrationLedgerSchema
{
    private const string SUFFIX = 'migrations';

    /**
     * The ledger's name on a site. The only place it is composed, so the table
     * declaration and the adoption of an installed ledger cannot disagree about
     * which table holds a host's history.
     */
    public static function nameFor(string $prefix, RuntimeIdentity $identity): Identifier
    {
        return Identifier::fromString($identity->tableName($prefix, self::SUFFIX));
    }

    public static function table(string $prefix, RuntimeIdentity $identity, string $charsetCollate): Table
    {
        return new Table(
            name: self::nameFor($prefix, $identity),
            columns: [
                Column::identifier('id'),
                Column::varchar('migration', 191),
                Column::intUnsigned('batch'),
                Column::dateTime('ran_at'),
            ],
            indexes: [
                Index::primary(IndexColumn::of('id')),
                Index::unique('migration', IndexColumn::of('migration')),
            ],
            engine: Engine::InnoDB,
            charsetCollate: $charsetCollate,
        );
    }
}
