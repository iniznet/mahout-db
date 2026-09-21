<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\GatewayQuery;
use Iniznet\Mahout\Db\Hooks;
use Iniznet\Mahout\Db\Internal\OptionSweepCursor;
use Iniznet\Mahout\Db\Internal\WpdbTableGateway;
use Iniznet\Mahout\Db\OrphanSweep;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Db\Tests\Fixtures\FixtureOrphanSource;
use Iniznet\Mahout\Db\Tests\Fixtures\OrphanFixtureTable;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The chunked orphan sweep: constant statements per chunk, a strictly
 * advancing cursor, and a tombstoned row that survives.
 *
 * @internal
 */
final class OrphanSweepTest extends TestCase
{
    public function testTheStatementCountPerChunkIsConstantWhileTheCursorAdvances(): void
    {
        $table = $this->orphanTable();
        $gateway = new WpdbTableGateway($this->connection());
        $cursor = new OptionSweepCursor();
        $sweep = new OrphanSweep($gateway, $cursor, $this->diagnostics());
        $source = new FixtureOrphanSource($this->connection(), $table);

        for ($i = 1; $i <= 6; ++$i) {
            $gateway->insert(Row::of($table, ['post_id' => 999, 'seq' => $i, 'tombstoned' => 0]));
        }

        // A runtime cap of zero stops the sweep after exactly one chunk, so each
        // call below is one chunk and the per-chunk cost is directly observable.
        $start = $this->statementCount();
        $sweep->sweep($source, 3, 0);
        $first = \count($this->sweepStatements($start));
        $position1 = $cursor->load($table->name->value);
        self::assertNotNull($position1);

        $start = $this->statementCount();
        $sweep->sweep($source, 3, 0);
        $second = \count($this->sweepStatements($start));
        $position2 = $cursor->load($table->name->value);
        self::assertNotNull($position2);

        self::assertSame($first, $second, 'the per-chunk statement count must not grow with the table');
        self::assertNotSame($position1, $position2, 'the cursor must advance between chunks');
        self::assertGreaterThan((int) $position1['seq'], (int) $position2['seq']);

        // A final call drains the remaining chunk and clears the cursor.
        $sweep->sweep($source, 3, 0);
        self::assertNull($cursor->load($table->name->value));
        self::assertSame([], $gateway->select(GatewayQuery::all($table, 100)));
    }

    public function testATombstonedRowSurvivesASweepAndTheCursorPassesIt(): void
    {
        $table = $this->orphanTable();
        $gateway = new WpdbTableGateway($this->connection());
        $sweep = new OrphanSweep($gateway, new OptionSweepCursor(), $this->diagnostics());
        $source = new FixtureOrphanSource($this->connection(), $table);

        $gateway->insert(Row::of($table, ['post_id' => 999, 'seq' => 1, 'tombstoned' => 1]));
        $gateway->insert(Row::of($table, ['post_id' => 999, 'seq' => 2, 'tombstoned' => 0]));

        $run = $sweep->sweep($source, 100, 25);

        self::assertSame(2, $run->examined);
        self::assertSame(1, $run->collected);

        $remaining = $gateway->select(GatewayQuery::all($table, 100));
        self::assertCount(1, $remaining);
        self::assertSame(1, (int) $remaining[0]->value('seq'));
    }

    public function testTheSweepFiresTheCollectedHookWithItsCount(): void
    {
        $table = $this->orphanTable();
        $gateway = new WpdbTableGateway($this->connection());
        $sweep = new OrphanSweep($gateway, new OptionSweepCursor(), $this->diagnostics());
        $source = new FixtureOrphanSource($this->connection(), $table);
        $gateway->insert(Row::of($table, ['post_id' => 999, 'seq' => 1, 'tombstoned' => 0]));

        $seen = [];
        \add_action(Hooks::ORPHANS_COLLECTED, static function (int $rows) use (&$seen): void {
            $seen[] = $rows;
        }, accepted_args: 1);

        $sweep->sweep($source, 100, 25);

        self::assertSame([1], $seen);
    }

    public function testARowWhosePostStillExistsIsNotCollected(): void
    {
        $table = $this->orphanTable();
        $gateway = new WpdbTableGateway($this->connection());
        $sweep = new OrphanSweep($gateway, new OptionSweepCursor(), $this->diagnostics());
        $source = new FixtureOrphanSource($this->connection(), $table);

        $postId = self::factory()->post->create();
        $gateway->insert(Row::of($table, ['post_id' => $postId, 'seq' => 1, 'tombstoned' => 0]));

        $run = $sweep->sweep($source, 100, 25);

        self::assertSame(0, $run->collected);
        self::assertCount(1, $gateway->select(GatewayQuery::all($table, 100)));
    }

    /**
     * @return list<string>
     */
    private function sweepStatements(int $since): array
    {
        $posts = $this->prefix().'posts';

        return \array_values(\array_filter(
            $this->statementsSince($since),
            static fn (string $statement): bool => \str_contains($statement, 'fixture_orphans')
                || \str_contains($statement, 'wptests_posts')
                || \str_contains($statement, $posts),
        ));
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
