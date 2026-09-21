<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Fixtures\Architecture;

/**
 * A deliberate violation: a hook name written inline instead of on a Hooks
 * class. The rule is mahout.arch.noRawHookName.
 *
 * @internal
 */
final class RawHookName
{
    public function emit(): void
    {
        \do_action('howdah/example/inline_event');
    }
}
