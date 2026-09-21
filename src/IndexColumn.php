<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Exception\InvalidIndex;

/**
 * One column of an index, with an optional prefix length.
 *
 * A prefix length is what makes a TEXT column indexable at all. It is capped at
 * 191 for the same reason a varchar is: utf8mb4's index cap.
 */
final readonly class IndexColumn
{
    private const int MAX_PREFIX = 191;

    private function __construct(
        public Identifier $name,
        public ?int $prefixLength,
    ) {
    }

    public static function of(string $name): self
    {
        return new self(Identifier::fromString($name), null);
    }

    public static function prefixed(string $name, int $length): self
    {
        if ($length < 1 || $length > self::MAX_PREFIX) {
            throw InvalidIndex::prefixLength($name, $length);
        }

        return new self(Identifier::fromString($name), $length);
    }

    public function definition(): string
    {
        return null === $this->prefixLength
            ? $this->name->quoted()
            : $this->name->quoted().'('.$this->prefixLength.')';
    }
}
