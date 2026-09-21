<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Exception\InvalidIndex;

/**
 * One declared index.
 *
 * The name is a real name for every kind but the primary key, which MySQL does
 * not allow to be named. The same definition serves CREATE TABLE and
 * ALTER TABLE ... ADD, because MySQL's clause is identical in both.
 */
final readonly class Index
{
    /**
     * @param list<IndexColumn> $columns
     */
    private function __construct(
        public Identifier $name,
        public IndexKind $kind,
        public array $columns,
    ) {
        if ([] === $columns) {
            throw InvalidIndex::noColumns($name->value);
        }
    }

    public static function primary(IndexColumn ...$columns): self
    {
        return new self(Identifier::fromString('PRIMARY'), IndexKind::Primary, \array_values($columns));
    }

    public static function unique(string $name, IndexColumn ...$columns): self
    {
        return new self(Identifier::fromString($name), IndexKind::Unique, \array_values($columns));
    }

    public static function key(string $name, IndexColumn ...$columns): self
    {
        return new self(Identifier::fromString($name), IndexKind::Key, \array_values($columns));
    }

    public static function fullText(string $name, IndexColumn ...$columns): self
    {
        return new self(Identifier::fromString($name), IndexKind::FullText, \array_values($columns));
    }

    /**
     * The declared column names, in index order.
     *
     * @return list<string>
     */
    public function columnNames(): array
    {
        return \array_map(
            static fn (IndexColumn $column): string => $column->name->value,
            $this->columns,
        );
    }

    public function definition(): string
    {
        $columns = \implode(', ', \array_map(
            static fn (IndexColumn $column): string => $column->definition(),
            $this->columns,
        ));

        if (IndexKind::Primary === $this->kind) {
            return IndexKind::Primary->value.' ('.$columns.')';
        }

        return $this->kind->value.' '.$this->name->quoted().' ('.$columns.')';
    }
}
