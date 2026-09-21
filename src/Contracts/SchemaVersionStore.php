<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Contracts;

/**
 * The stored schema version, compared to the code constant.
 *
 * One option read gates every run path, so a request whose schema is current
 * never touches the ledger. The option is not autoloaded: the gate is read on
 * the three run paths and never on the front end, so autoloading it would put
 * bytes on every front-end request to save a query on an admin request.
 */
interface SchemaVersionStore
{
    /** The stored version, or 0 when nothing has recorded one. */
    public function stored(): int;

    /** Record the code version after a whole batch committed. */
    public function record(int $version): void;
}
