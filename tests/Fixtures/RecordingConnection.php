<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Fixtures;

use Iniznet\Mahout\Db\Contracts\SqlConnection;

/**
 * A connection that records what it was asked to do and then delegates.
 *
 * It is how a negative proof is made mechanical: "the refusal happened before
 * any statement ran" is `writes() === []`.
 *
 * @internal
 */
final class RecordingConnection implements SqlConnection
{
    /** @var list<string> */
    private array $reads = [];

    /** @var list<string> */
    private array $writes = [];

    public function __construct(private readonly SqlConnection $inner)
    {
    }

    public function execute(string $statement): void
    {
        $this->writes[] = $statement;
        $this->inner->execute($statement);
    }

    public function executePrepared(string $statement, string|int ...$values): void
    {
        $this->writes[] = $statement;
        $this->inner->executePrepared($statement, ...$values);
    }

    public function rows(string $statement): array
    {
        $this->reads[] = $statement;

        return $this->inner->rows($statement);
    }

    public function rowsPrepared(string $statement, string|int ...$values): array
    {
        $this->reads[] = $statement;

        return $this->inner->rowsPrepared($statement, ...$values);
    }

    public function prefix(): string
    {
        return $this->inner->prefix();
    }

    public function charsetCollate(): string
    {
        return $this->inner->charsetCollate();
    }

    public function reset(): void
    {
        $this->reads = [];
        $this->writes = [];
    }

    /** @return list<string> */
    public function reads(): array
    {
        return $this->reads;
    }

    /** @return list<string> */
    public function writes(): array
    {
        return $this->writes;
    }
}
