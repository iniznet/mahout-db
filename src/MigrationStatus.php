<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * What wp mahout migrate --status prints: the stored version, the code version
 * and the pending set.
 */
final readonly class MigrationStatus
{
    /**
     * @param list<string> $applied
     * @param list<string> $pending
     */
    public function __construct(
        public int $codeVersion,
        public int $storedVersion,
        public array $applied,
        public array $pending,
    ) {
    }

    public function versionPending(): bool
    {
        return $this->codeVersion !== $this->storedVersion;
    }
}
