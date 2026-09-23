<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Fixtures;

use Iniznet\Mahout\Db\Contracts\StatementPreparer;

/**
 * A preparer that records what it was asked to render and returns a marker.
 *
 * The clause is text another party interpolates, so the assertion that matters
 * is "the value went through a placeholder and never into the statement". A
 * real connection cannot show that distinction -- the quoted text is the same
 * either way -- so this records the request instead of the result.
 *
 * @internal
 */
final class RecordingPreparer implements StatementPreparer
{
    /** @var list<string> */
    public array $statements = [];

    /** @var list<array<int|string, string|int>> */
    public array $values = [];

    public function prepare(string $statement, string|int ...$values): string
    {
        $this->statements[] = $statement;
        $this->values[] = $values;

        return $statement;
    }
}
