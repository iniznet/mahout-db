<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Internal;

use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Exception\ConnectionMissing;
use Iniznet\Mahout\Db\Exception\StatementFailed;

/**
 * The one place the package touches \$wpdb.
 *
 * A statement that carries no placeholder is passed through unmodified, because
 * wpdb::prepare() reports _doing_it_wrong() on a query with no placeholder -- so
 * DDL never goes near it. A statement that carries values is always prepared.
 *
 * @internal
 */
final readonly class WpdbConnection implements SqlConnection
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    /**
     * The composition-root named constructor. It resolves no collaborator; it
     * reads the one global WordPress publishes and builds a value.
     */
    public static function inWordPress(): self
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;

        if (!$wpdb instanceof \wpdb) {
            throw ConnectionMissing::withoutWpdb();
        }

        return new self($wpdb);
    }

    public function execute(string $statement): void
    {
        $result = $this->wpdb->query($statement);

        if (false === $result) {
            throw StatementFailed::forStatement($statement, $this->wpdb->last_error);
        }
    }

    public function executePrepared(string $statement, string|int ...$values): void
    {
        $this->execute($this->prepare($statement, $values));
    }

    public function rows(string $statement): array
    {
        return $this->collect($statement);
    }

    public function rowsPrepared(string $statement, string|int ...$values): array
    {
        return $this->collect($this->prepare($statement, $values));
    }

    public function prefix(): string
    {
        return $this->wpdb->prefix;
    }

    public function charsetCollate(): string
    {
        return $this->wpdb->get_charset_collate();
    }

    /**
     * @param array<int|string, string|int> $values
     */
    private function prepare(string $statement, array $values): string
    {
        $prepared = $this->wpdb->prepare($statement, ...$values);

        if (!\is_string($prepared)) {
            throw StatementFailed::unreadableResult($statement);
        }

        return $prepared;
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function collect(string $statement): array
    {
        $results = $this->wpdb->get_results($statement, ARRAY_A);

        // wpdb::get_results() returns an empty array both for a failed statement
        // and for an empty result set, because wpdb::query() flushes last_error
        // first and only refills it on failure. last_error is therefore the
        // signal; the return type is checked only so an unreadable result is
        // never silently treated as no rows.
        if ('' !== $this->wpdb->last_error) {
            throw StatementFailed::forStatement($statement, $this->wpdb->last_error);
        }

        if (!\is_array($results)) {
            throw StatementFailed::unreadableResult($statement);
        }

        $rows = [];
        foreach ($results as $row) {
            $normalised = [];
            foreach ($row as $key => $value) {
                $normalised[(string) $key] = \is_scalar($value) ? (string) $value : null;
            }
            $rows[] = $normalised;
        }

        return $rows;
    }
}
