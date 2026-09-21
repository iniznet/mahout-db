<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Unit;

use Iniznet\Mahout\Db\Exception\UnboundedStatement;
use Iniznet\Mahout\Db\GatewayQuery;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The bounded-query value object and STO-22's runtime floor.
 *
 * A leading prefix of the declared primary key is a bound; a LIMIT is a bound;
 * anything else is refused before a statement can exist.
 *
 * @internal
 */
final class GatewayQueryTest extends TestCase
{
    public function testAKeyThatCoversALeadingPrefixOfThePrimaryKeyIsBounded(): void
    {
        $table = $this->notesTable()->declared();
        $key = Row::of($table, ['object_kind' => 1, 'object_id' => 7]);

        $query = GatewayQuery::keyed($key);

        self::assertSame($key, $query->conditions);
        self::assertNull($query->limit);
    }

    public function testAFullPrimaryKeyEqualityIsAccepted(): void
    {
        $table = $this->notesTable()->declared();
        $key = Row::of($table, ['object_kind' => 1, 'object_id' => 7, 'field_id' => 'isbn']);

        self::assertSame($key, GatewayQuery::keyed($key)->conditions);
    }

    public function testAQueryWithNeitherAKeyNorALimitIsRefused(): void
    {
        $this->expectException(UnboundedStatement::class);

        GatewayQuery::keyed(Row::of($this->notesTable()->declared(), ['field_id' => 'isbn']));
    }

    public function testANonContiguousPrimaryKeySubsetIsRefused(): void
    {
        $this->expectException(UnboundedStatement::class);

        GatewayQuery::keyed(Row::of($this->notesTable()->declared(), [
            'object_kind' => 1,
            'field_id' => 'isbn',
        ]));
    }

    public function testTheRefusalNamesTheMissingBound(): void
    {
        try {
            GatewayQuery::keyed(Row::of($this->notesTable()->declared(), ['value_int' => 5]));
        } catch (UnboundedStatement $failure) {
            self::assertStringContainsString('neither a LIMIT nor a primary-key equality', $failure->getMessage());
            self::assertSame($this->valueTable(), $failure->table());

            return;
        }

        self::fail('an unbounded key must be refused');
    }

    public function testALimitOfOneOrMoreBoundsAnArbitraryPredicate(): void
    {
        $table = $this->notesTable()->declared();
        $conditions = Row::of($table, ['value_int' => 5]);

        self::assertSame(5, GatewayQuery::bounded($conditions, 5)->limit);
        self::assertSame(10, GatewayQuery::all($table, 10)->limit);
    }

    public function testALimitBelowOneIsRefused(): void
    {
        $this->expectException(UnboundedStatement::class);

        GatewayQuery::bounded(Row::of($this->notesTable()->declared(), []), 0);
    }
}
