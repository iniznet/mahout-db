<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Contracts\OrphanSource;
use Iniznet\Mahout\Db\Contracts\SweepCursor;
use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Kernel\Diagnostics;
use Iniznet\Mahout\Kernel\Level;

/**
 * The chunked, resumable, runtime-capped orphan sweep.
 *
 * Each chunk issues a fixed pipeline: one keyset select ordered by the primary
 * key, one batched existence probe over core's posts, and one tuple-IN delete
 * of the chunk's orphans. The statement count per chunk is therefore constant
 * while the cursor advances, which is what distinguishes a keyset walk from a
 * scan that happens to carry a LIMIT.
 *
 * A tombstoned row is skipped and the cursor advances past it. The sweep never
 * runs on a request path: DbProvider attaches it to mahout/db/gc, and the
 * theme schedules that event.
 */
final readonly class OrphanSweep
{
    private const int DEFAULT_BATCH = 500;

    private const int DEFAULT_MAX_RUNTIME = 25;

    public function __construct(
        private TableGateway $gateway,
        private SweepCursor $cursors,
        private Diagnostics $diagnostics,
    ) {
    }

    public function sweep(
        OrphanSource $source,
        int $batch = self::DEFAULT_BATCH,
        int $maxRuntimeSeconds = self::DEFAULT_MAX_RUNTIME,
    ): SweepRun {
        $table = $source->table();
        $tableName = $table->name->value;

        $after = $this->resume($table, $this->cursors->load($tableName));
        $started = \microtime(true);
        $examined = 0;
        $collected = 0;
        $stopped = false;
        $last = $after;

        while (true) {
            $rows = $this->gateway->chunk($table, $last, $batch);
            if ([] === $rows) {
                $this->cursors->clear($tableName);
                $last = null;

                break;
            }

            $examined += \count($rows);
            $orphans = $this->orphans($source, $rows);
            if ([] !== $orphans) {
                $this->gateway->deleteMany($orphans);
                $collected += \count($orphans);
            }

            $last = $rows[\count($rows) - 1];
            $this->cursors->save($tableName, $this->key($table, $last));

            if (\count($rows) < $batch) {
                $this->cursors->clear($tableName);

                break;
            }

            if (\microtime(true) - $started >= $maxRuntimeSeconds) {
                $stopped = true;

                break;
            }
        }

        $this->diagnostics->log(
            level: Level::Info,
            message: 'orphans swept',
            context: ['table' => $tableName, 'examined' => $examined, 'rows' => $collected],
        );

        \do_action(Hooks::ORPHANS_COLLECTED, $collected);

        return new SweepRun(
            examined: $examined,
            collected: $collected,
            stopped: $stopped,
            cursor: $stopped ? $last : null,
        );
    }

    /**
     * @param list<Row> $rows
     *
     * @return list<Row>
     */
    private function orphans(OrphanSource $source, array $rows): array
    {
        $live = \array_values(\array_filter(
            $rows,
            static fn (Row $row): bool => !$source->isTombstoned($row),
        ));

        if ([] === $live) {
            return [];
        }

        $ids = [];
        foreach ($live as $row) {
            $ids[$source->postOf($row)] = true;
        }

        $present = \array_fill_keys($source->existingPosts(\array_keys($ids)), true);

        return \array_values(\array_filter(
            $live,
            static fn (Row $row): bool => !\array_key_exists($source->postOf($row), $present),
        ));
    }

    /**
     * @param array<string, string|int>|null $key
     */
    private function resume(Table $table, ?array $key): ?Row
    {
        if (null === $key) {
            return null;
        }

        return Row::of($table, $key);
    }

    /**
     * @return array<string, string|int>
     */
    private function key(Table $table, Row $row): array
    {
        $key = [];
        foreach ($table->primaryKeyColumns() as $name) {
            $value = $row->value($name);
            if (null !== $value) {
                $key[$name] = $value;
            }
        }

        return $key;
    }
}
