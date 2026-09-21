<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * One row's values, bound to the declared table it belongs to.
 *
 * The array keys are the only place a column name enters the gateway, and every
 * key is checked against the schema object at construction. The SQL identifier
 * is read back from Column::name at statement-building time, so a caller never
 * supplies one.
 */
final readonly class Row
{
    /**
     * @param array<string, string|int|null> $values
     */
    private function __construct(
        public Table $table,
        private array $values,
    ) {
    }

    /**
     * @param array<string, string|int|null> $values
     *
     * @throws Exception\UnknownColumn when a key is not a declared column
     */
    public static function of(Table $table, array $values): self
    {
        foreach (\array_keys($values) as $name) {
            $table->column((string) $name);
        }

        return new self($table, $values);
    }

    /**
     * @return array<string, string|int|null>
     */
    public function values(): array
    {
        return $this->values;
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->values);
    }

    /**
     * @throws Exception\UnknownColumn when the row carries no value for the column
     */
    public function value(string $name): string|int|null
    {
        if (!\array_key_exists($name, $this->values)) {
            throw Exception\UnknownColumn::inRow($this->table->name->value, $name);
        }

        return $this->values[$name];
    }
}
