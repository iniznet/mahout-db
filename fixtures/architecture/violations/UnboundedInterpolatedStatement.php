<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Fixtures\Architecture;

/**
 * A deliberate violation, in the form the prefix is actually written in PHP:
 * an interpolated string. A rule that reads only scalar string literals never
 * sees this statement at all, which is why the prefixed case alone is not
 * enough evidence. The rule is mahout.arch.boundedHowdahStatement.
 *
 * @internal
 */
final class UnboundedInterpolatedStatement
{
    public function scan(\wpdb $wpdb): void
    {
        $wpdb->get_col("SELECT object_id FROM {$wpdb->prefix}howdah_readthrough");
    }
}
