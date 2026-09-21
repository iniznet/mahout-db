<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Internal;

use Iniznet\Mahout\Db\Contracts\SweepCursor;

/**
 * The sweep's cursor, persisted in one non-autoloaded option as a table-keyed
 * map of primary-key tuples.
 *
 * The cursor is a keyset position, so a sweep killed by its runtime cap resumes
 * exactly where it stopped instead of restarting at row one. The option is not
 * autoloaded because it is read by a cron or CLI path, never a request.
 *
 * @internal
 */
final class OptionSweepCursor implements SweepCursor
{
    private const string OPTION = 'mahout_db_sweep_cursors';

    public function load(string $table): ?array
    {
        return $this->read()[$table] ?? null;
    }

    public function save(string $table, array $key): void
    {
        $all = $this->read();
        $all[$table] = $key;
        $this->write($all);
    }

    public function clear(string $table): void
    {
        $all = $this->read();
        unset($all[$table]);
        $this->write($all);
    }

    /**
     * @return array<string, array<string, string|int>>
     */
    private function read(): array
    {
        $raw = \get_option(self::OPTION, []);
        if (!\is_array($raw)) {
            return [];
        }

        $all = [];
        foreach ($raw as $table => $key) {
            if (!\is_string($table) || !\is_array($key)) {
                continue;
            }

            $values = [];
            foreach ($key as $name => $value) {
                if (!\is_string($name) || (!\is_string($value) && !\is_int($value))) {
                    continue;
                }

                $values[$name] = $value;
            }

            $all[$table] = $values;
        }

        return $all;
    }

    /**
     * @param array<string, array<string, string|int>> $all
     */
    private function write(array $all): void
    {
        \update_option(self::OPTION, $all, false);
    }
}
