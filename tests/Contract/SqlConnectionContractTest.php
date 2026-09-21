<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Contract;

use Iniznet\Mahout\Db\Exception\StatementFailed;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The connection contract, against the real database.
 *
 * This is the only place \$wpdb is exercised, which is the point: the contract
 * exists so exactly one class touches it, and this test proves the wrapper
 * preserves what it needs to.
 *
 * @internal
 */
final class SqlConnectionContractTest extends TestCase
{
    public function testThePrefixIsReadFromWpdbOnce(): void
    {
        self::assertSame('wptests_', $this->connection()->prefix());
    }

    public function testTheCharsetCollationCarriesBothTheCharsetAndTheCollation(): void
    {
        $clause = $this->connection()->charsetCollate();

        self::assertStringContainsString('utf8mb4', $clause);
        self::assertStringContainsString('COLLATE', $clause);
    }

    public function testAStatementWithNoValuesIsExecutedUnprepared(): void
    {
        $connection = $this->connection();
        $name = $this->prefix().'sql_connection_probe';
        $this->dropTable($name);

        $connection->execute('CREATE TABLE '.$name.' (id int NOT NULL) ENGINE=InnoDB');

        self::assertTrue($this->tableExists($name));
        $this->dropTable($name);
    }

    public function testEveryBoundValueIsCarriedThroughUnchanged(): void
    {
        $rows = $this->connection()->rowsPrepared(
            'SELECT %s AS text_value, %d AS int_value',
            "a value with a ' quote and a %s that is not a placeholder",
            7,
        );

        self::assertCount(1, $rows);
        self::assertSame("a value with a ' quote and a %s that is not a placeholder", $rows[0]['text_value']);
        self::assertSame('7', $rows[0]['int_value']);
    }

    public function testAPreparedWriteCarriesAQuoteRatherThanBreakingTheStatement(): void
    {
        $connection = $this->connection();
        $name = $this->prefix().'sql_connection_probe';
        $this->dropTable($name);

        $connection->execute('CREATE TABLE '.$name.' (note varchar(191) NOT NULL) ENGINE=InnoDB');
        $connection->executePrepared('INSERT INTO '.$name.' (note) VALUES (%s)', "O'Brien; DROP TABLE x");

        $rows = $connection->rows('SELECT note FROM '.$name);
        self::assertSame(["O'Brien; DROP TABLE x"], \array_column($rows, 'note'));

        $this->dropTable($name);
    }

    public function testEveryColumnComesBackAsAStringOrNull(): void
    {
        $rows = $this->connection()->rows('SELECT 1 AS n, NULL AS nothing');

        self::assertSame('1', $rows[0]['n']);
        self::assertNull($rows[0]['nothing']);
    }

    public function testAFailedStatementThrowsWithTheDatabasesOwnError(): void
    {
        $connection = $this->connection();
        $exception = null;

        $this->silencingDatabaseErrors(static function () use ($connection, &$exception): void {
            try {
                $connection->execute('THIS IS NOT SQL;');
            } catch (StatementFailed $failure) {
                $exception = $failure;
            }
        });

        self::assertInstanceOf(StatementFailed::class, $exception);
        self::assertSame('THIS IS NOT SQL;', $exception->statement());
        self::assertNotSame('', $exception->error());
    }

    public function testAFailedPreparedStatementAlsoThrows(): void
    {
        $this->expectException(StatementFailed::class);

        $this->silencingDatabaseErrors(function (): void {
            $this->connection()->executePrepared('INSERT INTO a_table_that_does_not_exist (x) VALUES (%s)', 'x');
        });
    }

    public function testAReadOfAnUnknownTableThrowsRatherThanReturningEmpty(): void
    {
        $this->expectException(StatementFailed::class);

        $this->silencingDatabaseErrors(function (): void {
            $this->connection()->rows('SELECT * FROM a_table_that_does_not_exist');
        });
    }

    public function testTheContractCarriesNoTransactionMethod(): void
    {
        $methods = \array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(\Iniznet\Mahout\Db\Contracts\SqlConnection::class))->getMethods(),
        );

        self::assertSame(['execute', 'executePrepared', 'rows', 'rowsPrepared', 'prefix', 'charsetCollate'], $methods);
    }
}
