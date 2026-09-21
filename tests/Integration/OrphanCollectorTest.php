<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\DbProvider;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\GatewayQuery;
use Iniznet\Mahout\Db\Hooks;
use Iniznet\Mahout\Db\Internal\WpdbTableGateway;
use Iniznet\Mahout\Db\OrphanCollector;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Db\Tests\Fixtures\FixtureOrphanSource;
use Iniznet\Mahout\Db\Tests\Fixtures\OrphanFixtureTable;
use Iniznet\Mahout\Db\Tests\TestCase;
use Iniznet\Mahout\Kernel\Container;

/**
 * The immediate orphan path: a keyed delete for one post, and its attachment
 * to deleted_post.
 *
 * @internal
 */
final class OrphanCollectorTest extends TestCase
{
    public function testTheImmediatePathDeletesEveryRowForOnePostAndFiresTheHook(): void
    {
        $table = $this->orphanTable();
        $gateway = new WpdbTableGateway($this->connection());
        $source = new FixtureOrphanSource($this->connection(), $table);
        $collector = new OrphanCollector($gateway, $this->diagnostics());

        $gateway->insert(Row::of($table, ['post_id' => 7, 'seq' => 1, 'tombstoned' => 0]));
        $gateway->insert(Row::of($table, ['post_id' => 7, 'seq' => 2, 'tombstoned' => 0]));
        $gateway->insert(Row::of($table, ['post_id' => 8, 'seq' => 1, 'tombstoned' => 0]));

        $seen = [];
        \add_action(Hooks::ORPHANS_COLLECTED, static function (int $rows) use (&$seen): void {
            $seen[] = $rows;
        }, accepted_args: 1);

        self::assertSame(2, $collector->forPost(7, [$source]));
        self::assertSame([2], $seen);

        $remaining = $gateway->select(GatewayQuery::all($table, 100));
        self::assertCount(1, $remaining);
        self::assertSame(8, (int) $remaining[0]->value('post_id'));
    }

    public function testTheImmediatePathIsIdempotent(): void
    {
        $table = $this->orphanTable();
        $gateway = new WpdbTableGateway($this->connection());
        $source = new FixtureOrphanSource($this->connection(), $table);
        $collector = new OrphanCollector($gateway, $this->diagnostics());
        $gateway->insert(Row::of($table, ['post_id' => 7, 'seq' => 1, 'tombstoned' => 0]));

        self::assertSame(1, $collector->forPost(7, [$source]));
        self::assertSame(0, $collector->forPost(7, [$source]));
    }

    public function testTheProviderDeletesOrphansWhenCoreFiresDeletedPost(): void
    {
        $table = $this->orphanTable();
        $source = new FixtureOrphanSource($this->connection(), $table);

        $container = new Container();
        $container->set($this->diagnostics());
        $provider = new DbProvider();
        $provider->register($container);
        $provider->boot($container);

        \add_filter(Hooks::ORPHAN_SOURCES, static fn (array $sources): array => [...$sources, $source], accepted_args: 1);

        $postId = self::factory()->post->create();
        $gateway = new WpdbTableGateway($this->connection());
        $gateway->insert(Row::of($table, ['post_id' => $postId, 'seq' => 1, 'tombstoned' => 0]));

        \wp_delete_post($postId, true);

        self::assertSame([], $gateway->select(GatewayQuery::all($table, 100)));
    }

    private function orphanTable(): Table
    {
        $fixture = new OrphanFixtureTable(
            new DdlEmitter(),
            OrphanFixtureTable::nameFor($this->prefix()),
            $this->charsetCollate(),
        );

        $this->dropTable($fixture->declared()->name->value);
        $this->connection()->execute($fixture->create());

        return $fixture->declared();
    }
}
