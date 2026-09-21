<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * The migration ledger: one row per applied migration, with its batch and the
 * timestamp it ran. The batch column is the reason --rollback can revert the
 * last batch in reverse order.
 *
 * A value factory: it resolves no collaborator, so it is a permitted static
 * call. The prefix arrives as an argument, so it is applied when the schema is
 * declared.
 */
final readonly class MigrationLedgerSchema
{
    private const string SUFFIX = 'mahout_migrations';

    public static function table(string $prefix, string $charsetCollate): Table
    {
        return new Table(
            name: Identifier::prefixed($prefix, self::SUFFIX),
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
