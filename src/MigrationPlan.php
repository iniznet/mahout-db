<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * What wp mahout migrate --dry-run prints, and nothing more: the plan is the
 * read-only half of a run, so it writes nothing, including the ledger and the
 * schema version option.
 */
final readonly class MigrationPlan
{
    /**
     * @param list<string> $pending the migrations a run would apply, in order
     * @param list<string> $applied the migrations the ledger already records
     */
    public function __construct(
        public array $pending,
        public array $applied,
        public int $batch,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->pending;
    }
}
