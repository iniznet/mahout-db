<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Contracts;

/**
 * Whether the search index exists, as a cached fact rather than a query.
 *
 * present() reads one non-autoloaded option and issues no statement, so it is
 * safe on a request path. refresh() performs the single information_schema read
 * and rewrites the option, and is called from mahout/db/after_migrate.
 */
interface SearchIndexPresence
{
    public function present(): bool;

    public function refresh(): bool;
}
