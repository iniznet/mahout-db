<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Contracts\OrphanSource;
use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Kernel\Diagnostics;
use Iniznet\Mahout\Kernel\Level;

/**
 * The immediate orphan path: every row that references a deleted post, removed
 * by a keyed select and a primary-key delete.
 *
 * It is attached to deleted_post at priority 20 and never on a front-end read.
 * It is idempotent: running it twice removes nothing the second time.
 */
final readonly class OrphanCollector
{
    public function __construct(
        private TableGateway $gateway,
        private Diagnostics $diagnostics,
    ) {
    }

    /**
     * @param list<OrphanSource> $sources
     */
    public function forPost(int $postId, array $sources): int
    {
        $collected = 0;
        foreach ($sources as $source) {
            $rows = $this->gateway->select(GatewayQuery::keyed($source->keyForPost($postId)));
            if ([] === $rows) {
                continue;
            }

            $this->gateway->deleteMany($rows);
            $collected += \count($rows);
        }

        if ($collected > 0) {
            $this->diagnostics->log(
                level: Level::Info,
                message: 'orphans collected by the immediate path',
                context: ['post' => $postId, 'rows' => $collected],
            );
        }

        \do_action(Hooks::ORPHANS_COLLECTED, $collected);

        return $collected;
    }
}
