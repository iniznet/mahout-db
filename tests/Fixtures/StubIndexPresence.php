<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Fixtures;

use Iniznet\Mahout\Db\Contracts\SearchIndexPresence;

/**
 * A declared answer to whether the search index exists.
 *
 * The presence contract reads one cached option, and the refresh performs a
 * schema read. A test that only branches on presence says which of the two it
 * means by handing over the answer, and the integration tests cover the real
 * option against a real database.
 *
 * @internal
 */
final readonly class StubIndexPresence implements SearchIndexPresence
{
    public function __construct(private bool $present)
    {
    }

    public function present(): bool
    {
        return $this->present;
    }

    public function refresh(): bool
    {
        return $this->present;
    }
}
