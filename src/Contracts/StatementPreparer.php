<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Contracts;

/**
 * A statement rendered with every value bound through a placeholder, and not
 * executed.
 *
 * {@see SqlConnection} is the boundary that runs a statement. This is the
 * boundary for the one case where a statement is not this package's to run:
 * a fragment another party interpolates into its own query. The FULLTEXT
 * search clause is that case -- core's `WP_Query` glues the fragment into its
 * `WHERE`, so the clause must leave this package as text, and text that
 * carries a visitor-supplied value must still have been through a placeholder.
 *
 * The contract therefore has exactly one method, and it never touches the
 * database: there is nothing to read, nothing to write, and no result to
 * collect.
 */
interface StatementPreparer
{
    /**
     * The statement with every value quoted and escaped.
     *
     * A placeholder and a value that do not correspond is a refusal, never a
     * half-built statement.
     */
    public function prepare(string $statement, string|int ...$values): string;
}
