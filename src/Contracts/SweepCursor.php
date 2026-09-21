<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Contracts;

/**
 * The resumable position of a sweep, persisted by table.
 *
 * The key is the full primary-key tuple of the last processed row, so the
 * cursor is a keyset position rather than an offset and a run resumes exactly
 * where it stopped.
 */
interface SweepCursor
{
    /**
     * @return array<string, string|int>|null
     */
    public function load(string $table): ?array;

    /**
     * @param array<string, string|int> $key
     */
    public function save(string $table, array $key): void;

    public function clear(string $table): void;
}
