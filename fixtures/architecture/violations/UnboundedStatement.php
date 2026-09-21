<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Fixtures\Architecture;

/**
 * A deliberate violation: a statement against a howdah table with no LIMIT and
 * no primary-key equality. The rule is
 * mahout.arch.boundedHowdahStatement.
 *
 * @internal
 */
final class UnboundedStatement
{
    public function scan(\wpdb $wpdb): void
    {
        $wpdb->get_results('SELECT object_id FROM howdah_values');
    }
}
