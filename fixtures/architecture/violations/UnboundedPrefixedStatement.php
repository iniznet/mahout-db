<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Fixtures\Architecture;

/**
 * A deliberate violation: a statement against a real table name with neither a
 * LIMIT nor a primary-key equality. The rule is
 * mahout.arch.boundedHowdahStatement.
 *
 * The name carries the test suite's prefix, because that is what a table is
 * called at runtime: the declaration is "{$wpdb->prefix}howdah_<entity>" and
 * the prefix here is "wptests_". A fixture with the bare name never proved the
 * rule fires on a table this site actually has.
 *
 * @internal
 */
final class UnboundedPrefixedStatement
{
    public function scan(\wpdb $wpdb): void
    {
        $wpdb->get_results('SELECT object_id FROM wptests_howdah_values');
    }
}
