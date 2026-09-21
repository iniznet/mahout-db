<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Unit;

use Iniznet\Mahout\Db\CliExitCode;
use Iniznet\Mahout\Db\Exception\MigrationRollbackRefused;
use Iniznet\Mahout\Db\Exception\StatementFailed;
use Iniznet\Mahout\Db\MigrationStatus;
use Iniznet\Mahout\Db\SchemaVersion;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The one-option gate and the CLI exit codes.
 *
 * @internal
 */
final class VersionGateTest extends TestCase
{
    public function testAMatchingVersionIsNotPending(): void
    {
        self::assertFalse((new SchemaVersion(code: 1, stored: 1))->pending());
    }

    public function testAVersionThatHasNotBeenStoredIsPending(): void
    {
        self::assertTrue((new SchemaVersion(code: 1, stored: 0))->pending());
    }

    public function testAStoredVersionAheadOfTheCodeIsAlsoPending(): void
    {
        self::assertTrue((new SchemaVersion(code: 1, stored: 2))->pending());
    }

    public function testTheStatusReportsWhetherTheVersionIsPending(): void
    {
        $pending = new MigrationStatus(codeVersion: 2, storedVersion: 1, applied: [], pending: ['fixture/0001']);
        self::assertTrue($pending->versionPending());
        self::assertSame(['fixture/0001'], $pending->pending);

        $current = new MigrationStatus(codeVersion: 2, storedVersion: 2, applied: [], pending: []);
        self::assertFalse($current->versionPending());
    }

    public function testARefusalHasItsOwnExitCodeSoAScriptDoesNotRetryIt(): void
    {
        self::assertSame(CliExitCode::Refused, CliExitCode::forFailure(MigrationRollbackRefused::forMigrations(['a'])));
        self::assertSame(3, CliExitCode::Refused->value);
    }

    public function testEveryOtherFailureIsAPlainFailure(): void
    {
        self::assertSame(CliExitCode::Failure, CliExitCode::forFailure(StatementFailed::forStatement('s', 'e')));
        self::assertSame(CliExitCode::Failure, CliExitCode::forFailure(new \RuntimeException('boom')));
    }

    public function testTheExitCodeSetIsTheOneTheCliContractFixes(): void
    {
        self::assertSame(0, CliExitCode::Success->value);
        self::assertSame(1, CliExitCode::Failure->value);
        self::assertSame(2, CliExitCode::Usage->value);
        self::assertSame(3, CliExitCode::Refused->value);
    }
}
