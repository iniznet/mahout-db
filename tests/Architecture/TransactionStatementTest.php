<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Architecture;

use Iniznet\Mahout\Db\Exception\MigrationRollbackRefused;
use Iniznet\Mahout\Db\Tests\TestCase;
use Iniznet\Mahout\Devtools\Rules\TransactionOnlyInGatewayRule;

/**
 * No string literal in this package is a transaction statement.
 *
 * The scan is over string literals rather than over file text, because a
 * docblock that explains why a rollback is refused is prose and a literal is
 * code.
 *
 * The matcher is the analyzer rule's own public pattern rather than a copy of
 * it. A repeated pattern is a second definition of the question — the first
 * version of this test repeated the rule's pattern and inherited the rule's
 * defect, firing on the word inside an exception message — so the gate and this
 * proof cannot drift apart. The prose case is the message the package actually
 * throws, read from the exception rather than retyped, so a reworded message is
 * checked by this test the moment it changes.
 *
 * @internal
 */
final class TransactionStatementTest extends TestCase
{
    public function testEveryTransactionStatementInTheSourceLivesInTheGateway(): void
    {
        $carriers = [];
        foreach ($this->sourceFiles() as $file) {
            foreach ($this->stringLiterals($file) as $literal) {
                if ($this->isTransactionStatement($literal)) {
                    $carriers[\basename($file)] = true;
                }
            }
        }

        self::assertSame(['WpdbTableGateway.php'], \array_keys($carriers));
    }

    public function testTheMatcherCatchesAStatementInStatementPosition(): void
    {
        self::assertTrue($this->isTransactionStatement('START TRANSACTION'));
        self::assertTrue($this->isTransactionStatement('COMMIT'));
        self::assertTrue($this->isTransactionStatement('ROLLBACK'));
        self::assertTrue($this->isTransactionStatement('start transaction'));
        self::assertTrue($this->isTransactionStatement('  ROLLBACK;'));
        self::assertTrue($this->isTransactionStatement('SET autocommit = 0; COMMIT'));
    }

    public function testTheMatcherLeavesProseAndOrdinaryStatementsAlone(): void
    {
        // The refusal names a rollback, and it says so in an exception message.
        self::assertFalse($this->isTransactionStatement(
            MigrationRollbackRefused::forMigrations(['Mahout_Example'])->getMessage(),
        ));
        self::assertFalse($this->isTransactionStatement('The reversal is refused before any statement runs.'));
        self::assertFalse($this->isTransactionStatement('SELECT id FROM wp_posts LIMIT 1'));
        self::assertFalse($this->isTransactionStatement('CREATE TABLE IF NOT EXISTS x (id int)'));
    }

    public function testTheScanReadSomeLiteralsSoItIsNotVacuous(): void
    {
        $literals = [];
        foreach ($this->sourceFiles() as $file) {
            $literals = [...$literals, ...$this->stringLiterals($file)];
        }

        self::assertGreaterThan(50, \count($literals));
    }

    private function isTransactionStatement(string $literal): bool
    {
        return 1 === \preg_match(TransactionOnlyInGatewayRule::STATEMENT_PATTERN, $literal);
    }

    /**
     * Every single- and double-quoted literal in a file, without its quotes.
     *
     * @return list<string>
     */
    private function stringLiterals(string $file): array
    {
        $literals = [];

        foreach (\token_get_all((string) \file_get_contents($file)) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $literals[] = \trim($token[1], "'\"");
            }
        }

        return $literals;
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src'),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        \sort($files);

        return $files;
    }
}
