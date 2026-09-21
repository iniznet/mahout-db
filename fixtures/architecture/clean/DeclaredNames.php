<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Fixtures\Architecture;

use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Hooks;

/**
 * The clean half of the architecture fixtures: the same three behaviours the
 * violation fixtures get wrong, written the way this package requires.
 *
 * Nothing here may be reported, which is what makes the violations beside it
 * evidence that the rules fire rather than evidence that PHPStan ran.
 *
 * @internal
 */
final readonly class DeclaredNames
{
    public function __construct(private SqlConnection $connection)
    {
    }

    /** A hook name comes from a constant. */
    public function declaredHook(): void
    {
        \add_action(Hooks::BEFORE_MIGRATE, static function (): void {});
    }

    /** A statement goes through the connection and carries its own bound. */
    public function boundedRead(): void
    {
        $this->connection->rowsPrepared('SELECT id FROM wp_posts WHERE ID = %d LIMIT 1', 1);
    }
}
