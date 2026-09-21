<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * What a run did: the batch it used and the migrations it applied or reversed,
 * in the order it touched them.
 */
final readonly class MigrationRun
{
    /**
     * @param list<string> $migrations
     */
    public function __construct(
        public int $batch,
        public array $migrations,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->migrations;
    }

    public function count(): int
    {
        return \count($this->migrations);
    }
}
