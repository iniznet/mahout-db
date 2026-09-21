<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\Exception\UnknownColumn;
use Iniznet\Mahout\Db\GatewayQuery;
use Iniznet\Mahout\Db\Internal\WpdbTableGateway;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The typed table gateway against the real database.
 *
 * @internal
 */
final class TableGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTable($this->valueTable());
        $this->connection()->execute($this->notesTable()->create());
    }

    public function testItInsertsAndReadsARowBackByItsPrimaryKey(): void
    {
        $table = $this->notesTable()->declared();
        $gateway = $this->gateway();

        $gateway->insert(Row::of($table, [
            'object_kind' => 1,
            'object_id' => 7,
            'field_id' => 'isbn',
            'value_text' => '978',
        ]));

        $rows = $gateway->select(GatewayQuery::keyed(Row::of($table, [
            'object_kind' => 1,
            'object_id' => 7,
            'field_id' => 'isbn',
        ])));

        self::assertCount(1, $rows);
        self::assertSame('978', $rows[0]->value('value_text'));
        self::assertSame(7, (int) $rows[0]->value('object_id'));
    }

    public function testASelectReturnsOnlyTheDeclaredColumns(): void
    {
        $table = $this->notesTable()->declared();
        $gateway = $this->gateway();
        $gateway->insert(Row::of($table, ['object_kind' => 1, 'object_id' => 1, 'field_id' => 'f']));

        $rows = $gateway->select(GatewayQuery::all($table, 1));

        self::assertSame(
            ['object_kind', 'object_id', 'field_id', 'value_text', 'value_int', 'value_dec', 'value_date'],
            \array_keys($rows[0]->values()),
        );
    }

    public function testItUpdatesAKeyedRow(): void
    {
        $table = $this->notesTable()->declared();
        $gateway = $this->gateway();
        $key = Row::of($table, ['object_kind' => 1, 'object_id' => 1, 'field_id' => 'f']);
        $gateway->insert($key);

        $gateway->update(Row::of($table, ['value_int' => 42]), GatewayQuery::keyed($key));

        $rows = $gateway->select(GatewayQuery::keyed($key));
        self::assertSame(42, (int) $rows[0]->value('value_int'));
    }

    public function testAnUpsertReplacesTheRowWithTheSamePrimaryKey(): void
    {
        $table = $this->notesTable()->declared();
        $gateway = $this->gateway();
        $row = Row::of($table, ['object_kind' => 1, 'object_id' => 1, 'field_id' => 'f', 'value_text' => 'first']);
        $gateway->insert($row);

        $gateway->upsert(Row::of($table, ['object_kind' => 1, 'object_id' => 1, 'field_id' => 'f', 'value_text' => 'second']));

        $rows = $gateway->select(GatewayQuery::all($table, 10));
        self::assertCount(1, $rows);
        self::assertSame('second', $rows[0]->value('value_text'));
    }

    public function testItDeletesAKeyedPredicate(): void
    {
        $table = $this->notesTable()->declared();
        $gateway = $this->gateway();
        $gateway->insert(Row::of($table, ['object_kind' => 1, 'object_id' => 1, 'field_id' => 'a']));
        $gateway->insert(Row::of($table, ['object_kind' => 1, 'object_id' => 1, 'field_id' => 'b']));

        $gateway->delete(GatewayQuery::keyed(Row::of($table, ['object_kind' => 1, 'object_id' => 1])));

        self::assertSame([], $gateway->select(GatewayQuery::all($table, 10)));
    }

    public function testDeleteManyRemovesEveryRowByItsFullPrimaryKeyInOneStatement(): void
    {
        $table = $this->notesTable()->declared();
        $gateway = $this->gateway();
        for ($i = 1; $i <= 3; ++$i) {
            $gateway->insert(Row::of($table, ['object_kind' => 1, 'object_id' => $i, 'field_id' => 'f']));
        }

        $rows = $gateway->select(GatewayQuery::all($table, 10));
        $gateway->deleteMany($rows);

        self::assertSame([], $gateway->select(GatewayQuery::all($table, 10)));
    }

    public function testAChunkWalksPrimaryKeyOrderAndResumesAfterACursor(): void
    {
        $table = $this->notesTable()->declared();
        $gateway = $this->gateway();
        for ($i = 1; $i <= 5; ++$i) {
            $gateway->insert(Row::of($table, ['object_kind' => 1, 'object_id' => $i, 'field_id' => 'f']));
        }

        $first = $gateway->chunk($table, null, 2);
        self::assertCount(2, $first);
        self::assertSame(1, (int) $first[0]->value('object_id'));

        $second = $gateway->chunk($table, $first[1], 2);
        self::assertCount(2, $second);
        self::assertSame(3, (int) $second[0]->value('object_id'));
    }

    public function testTheGatewayRejectsACallerSuppliedTableName(): void
    {
        $this->expectException(\TypeError::class);

        // Deliberately the wrong type: a raw table name is not a schema object.
        $this->gateway()->select('wptests_posts');
    }

    public function testTheGatewayRejectsACallerSuppliedColumnName(): void
    {
        $this->expectException(UnknownColumn::class);

        Row::of($this->notesTable()->declared(), ['object_kind' => 1, 'injected = 1 OR 1=1' => 1]);
    }

    private function gateway(): WpdbTableGateway
    {
        return new WpdbTableGateway($this->connection());
    }
}
