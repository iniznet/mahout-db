<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Contracts;

/**
 * The database connection, behind one boundary.
 *
 * Two rules make this the only place the package touches \$wpdb:
 *
 * 1. Every value goes through a placeholder. A statement that carries values
 *    is built by the *Prepared methods and never by concatenation.
 * 2. Identifiers are never parameters. A caller reads them from a schema
 *    object, and this contract never accepts one as a caller-supplied string.
 *
 * The contract deliberately has no transaction method. The transaction boundary
 * has exactly one owner, and that owner is the gateway, not this connection.
 */
interface SqlConnection
{
    /** Execute a statement that carries no values. */
    public function execute(string $statement): void;

    /** Execute a statement with every value bound through a placeholder. */
    public function executePrepared(string $statement, string|int ...$values): void;

    /**
     * Read a result set. Every value is normalised to a string or null.
     *
     * @return list<array<string, string|null>>
     */
    public function rows(string $statement): array;

    /**
     * Read a result set with every value bound through a placeholder.
     *
     * @return list<array<string, string|null>>
     */
    public function rowsPrepared(string $statement, string|int ...$values): array;

    /**
     * The site's table prefix, read once by a schema declaration and never by a
     * query.
     */
    public function prefix(): string;

    /** The charset and collation clause for a table declaration. */
    public function charsetCollate(): string;
}
