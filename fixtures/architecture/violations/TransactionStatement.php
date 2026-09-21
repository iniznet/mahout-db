<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Fixtures\Architecture;

/**
 * A deliberate violation: a transaction statement outside the gateway. The rule
 * is mahout.arch.transactionOnlyInGateway.
 *
 * @internal
 */
final class TransactionStatement
{
    public function open(\wpdb $wpdb): void
    {
        $wpdb->query('START TRANSACTION');
    }
}
