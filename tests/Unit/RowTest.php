<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Unit;

use Iniznet\Mahout\Db\Exception\UnknownColumn;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The row value object: a declared column key is the only identifier it
 * accepts, and an undeclared one is rejected.
 *
 * @internal
 */
final class RowTest extends TestCase
{
    public function testARowReadsBackTheValuesItWasGiven(): void
    {
        $row = Row::of($this->notesTable()->declared(), [
            'object_kind' => 1,
            'object_id' => 7,
            'field_id' => 'isbn',
        ]);

        self::assertTrue($row->has('field_id'));
        self::assertSame('isbn', $row->value('field_id'));
        self::assertSame(1, $row->value('object_kind'));
        self::assertFalse($row->has('value_text'));
    }

    public function testARowRejectsAColumnTheTableDoesNotDeclare(): void
    {
        $this->expectException(UnknownColumn::class);

        Row::of($this->notesTable()->declared(), ['a_column_that_does_not_exist' => 1]);
    }

    public function testARowRefusesAValueForAColumnItDoesNotCarry(): void
    {
        $this->expectException(UnknownColumn::class);

        Row::of($this->notesTable()->declared(), ['object_kind' => 1])->value('object_id');
    }
}
