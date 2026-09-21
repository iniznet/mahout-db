<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Fixtures;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Identifier;

/**
 * The fixture migrations, selected by key and returned in a fixed order.
 *
 * @internal
 */
final class FixtureSet
{
    public const string VALUE = 'value';

    public const string META = 'meta';

    public const string OBJECT_INDEX = 'object_index';

    public const string DROP_DECIMAL = 'drop_decimal';

    /**
     * @param list<string> $keys
     *
     * @return list<Migration>
     */
    public static function of(array $keys, SqlConnection $connection, string $prefix, string $charsetCollate): array
    {
        $emitter = new DdlEmitter();
        $notes = new NotesTable(
            emitter: $emitter,
            name: NotesTable::nameFor($prefix),
            charsetCollate: $charsetCollate,
        );

        $declarations = [
            self::VALUE => static fn (): Migration => new CreatesTheValueTable($connection, $notes),
            self::META => static fn (): Migration => new CreatesTheMetaTable(
                connection: $connection,
                emitter: $emitter,
                name: Identifier::prefixed($prefix, 'fixture_meta'),
                charsetCollate: $charsetCollate,
            ),
            self::OBJECT_INDEX => static fn (): Migration => new AddsTheIntegerIndex($connection, $emitter, $notes->name()),
            self::DROP_DECIMAL => static fn (): Migration => new DropsTheDecimalColumn($connection, $emitter, $notes->name()),
        ];

        $migrations = [];
        foreach ($keys as $key) {
            $migrations[] = $declarations[$key]();
        }

        return $migrations;
    }
}
