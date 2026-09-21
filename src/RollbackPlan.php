<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Contracts\Migration;

/**
 * What wp mahout migrate --rollback --dry-run prints.
 *
 * The plan is built before the first statement of a rollback runs, which is how
 * an irreversible migration refuses the whole batch instead of half of it.
 */
final readonly class RollbackPlan
{
    /**
     * @param list<Migration> $reversals the migrations to reverse, in the order they are reversed
     */
    public function __construct(
        public int $batch,
        public array $reversals,
    ) {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return \array_map(
            static fn (Migration $migration): string => $migration->name(),
            $this->reversals,
        );
    }

    public function isEmpty(): bool
    {
        return [] === $this->reversals;
    }
}
