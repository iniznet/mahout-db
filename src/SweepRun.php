<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * What one sweep did: how many rows it examined, how many it removed, whether
 * the runtime cap stopped it, and the cursor to resume from when it did.
 */
final readonly class SweepRun
{
    public function __construct(
        public int $examined,
        public int $collected,
        public bool $stopped,
        public ?Row $cursor,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->examined;
    }
}
