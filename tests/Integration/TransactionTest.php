<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\Exception\StatementFailed;
use Iniznet\Mahout\Db\GatewayQuery;
use Iniznet\Mahout\Db\Internal\WpdbTableGateway;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Db\Tests\Fixtures\RecordingConnection;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The transaction boundary: atomicity, joining, and the negative cases.
 *
 * @internal
 */
final class TransactionTest extends TestCase
{
    private RecordingConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->recording();
        $this->dropTable($this->valueTable());
        $this->connection->execute($this->notesTable()->create());
    }

    public function testACommittedTransactionWritesEveryRow(): void
    {
        $table = $this->notesTable()->declared();
        $gateway = new WpdbTableGateway($this->connection);

        $gateway->transactional(function () use ($gateway, $table): void {
            $gateway->insert($this->row($table, 'a'));
            $gateway->insert($this->row($table, 'b'));
        });

        self::assertCount(2, $gateway->select(GatewayQuery::all($table, 10)));
        self::assertContains('START TRANSACTION', $this->connection->writes());
        self::assertContains('COMMIT', $this->connection->writes());
    }

    public function testASecondStatementThatFailsLeavesZeroRowsChanged(): void
    {
        $table = $this->notesTable()->declared();
        $gateway = new WpdbTableGateway($this->connection);
        $this->connection->reset();

        $this->silencingDatabaseErrors(function () use ($gateway, $table): void {
            try {
                $gateway->transactional(function () use ($gateway, $table): void {
                    $gateway->insert($this->row($table, 'a'));
                    $gateway->insert($this->row($table, 'a')); // duplicate primary key
                });
            } catch (StatementFailed) {
                // asserted below; the state is the interesting part
            }
        });

        self::assertSame([], $gateway->select(GatewayQuery::all($table, 10)));
        self::assertContains('START TRANSACTION', $this->connection->writes());
        self::assertContains('ROLLBACK', $this->connection->writes());
        self::assertNotContains('COMMIT', $this->connection->writes());
    }

    public function testANestedCallJoinsTheOuterTransactionRatherThanNesting(): void
    {
        $table = $this->notesTable()->declared();
        $gateway = new WpdbTableGateway($this->connection);
        $this->connection->reset();

        $gateway->transactional(function () use ($gateway, $table): void {
            $gateway->insert($this->row($table, 'a'));
            $gateway->transactional(function () use ($gateway, $table): void {
                $gateway->insert($this->row($table, 'b'));
            });
        });

        $writes = $this->connection->writes();
        self::assertCount(1, \array_filter($writes, static fn (string $s): bool => 'START TRANSACTION' === $s));
        self::assertCount(1, \array_filter($writes, static fn (string $s): bool => 'COMMIT' === $s));
        self::assertCount(2, $gateway->select(GatewayQuery::all($table, 10)));
    }

    public function testANestedFailureRollsBackTheWholeTransaction(): void
    {
        $table = $this->notesTable()->declared();
        $gateway = new WpdbTableGateway($this->connection);
        $this->connection->reset();

        $this->silencingDatabaseErrors(function () use ($gateway, $table): void {
            try {
                $gateway->transactional(function () use ($gateway, $table): void {
                    $gateway->insert($this->row($table, 'a'));
                    $gateway->transactional(function () use ($gateway, $table): void {
                        $gateway->insert($this->row($table, 'b'));
                        $gateway->insert($this->row($table, 'b')); // duplicate primary key
                    });
                });
            } catch (StatementFailed) {
                // asserted below
            }
        });

        self::assertSame([], $gateway->select(GatewayQuery::all($table, 10)));
        self::assertContains('ROLLBACK', $this->connection->writes());
        self::assertNotContains('COMMIT', $this->connection->writes());
    }

    public function testNothingIsRetriedAndNothingIsSubstituted(): void
    {
        $table = $this->notesTable()->declared();
        $gateway = new WpdbTableGateway($this->connection);

        $this->silencingDatabaseErrors(function () use ($gateway, $table): void {
            try {
                $gateway->transactional(function () use ($gateway, $table): void {
                    $gateway->insert($this->row($table, 'a'));
                    $gateway->insert($this->row($table, 'a'));
                });
            } catch (StatementFailed) {
                // asserted below
            }
        });

        // A retry would show a second START TRANSACTION after the ROLLBACK.
        $writes = $this->connection->writes();
        self::assertCount(1, \array_filter($writes, static fn (string $s): bool => 'START TRANSACTION' === $s));
    }

    private function row(Table $table, string $field): Row
    {
        return Row::of($table, ['object_kind' => 1, 'object_id' => 1, 'field_id' => $field]);
    }
}
