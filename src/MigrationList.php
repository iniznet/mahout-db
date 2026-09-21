<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Exception\InvalidMigrationList;
use Iniznet\Mahout\Db\Exception\MigrationNameCollision;

/**
 * The registered migrations, in declaration order, with names that are unique.
 *
 * Order is the contract: a migration runs after the migrations declared before
 * it, and a rollback reverses a batch in reverse order.
 */
final readonly class MigrationList
{
    /**
     * @param list<Migration> $migrations
     */
    private function __construct(public array $migrations)
    {
    }

    /**
     * Validate the payload of the mahout/db/migrations filter.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function fromHookPayload(array $payload): self
    {
        $validated = [];
        $names = [];

        foreach ($payload as $migration) {
            if (!$migration instanceof Migration) {
                throw InvalidMigrationList::notAMigration(Hooks::MIGRATIONS);
            }

            $name = $migration->name();
            if (\array_key_exists($name, $names)) {
                throw MigrationNameCollision::forName($name);
            }

            $names[$name] = true;
            $validated[] = $migration;
        }

        return new self($validated);
    }

    /**
     * @return list<Migration>
     */
    public function all(): array
    {
        return $this->migrations;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return \array_map(static fn (Migration $migration): string => $migration->name(), $this->migrations);
    }

    public function named(string $name): ?Migration
    {
        foreach ($this->migrations as $migration) {
            if ($migration->name() === $name) {
                return $migration;
            }
        }

        return null;
    }
}
