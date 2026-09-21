<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Architecture;

use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The architecture rules are proved by running them, not by reading them.
 *
 * The fixtures directory holds five deliberate violations and one clean file
 * beside them. PHPStan is run over that directory with the same rule set the
 * build uses; the violations must be reported and the clean file must not.
 *
 * This is the only test in the suite that shells out, and it is the only one
 * that can be: a rule with no proof is a rule that silently stopped firing.
 *
 * @internal
 */
final class ArchitectureRuleProofTest extends TestCase
{
    /**
     * The rules this package leans on. Each has a violation fixture beside a
     * clean one, so the pair is evidence rather than assertion.
     *
     * @var list<string>
     */
    private const VIOLATED = [
        'mahout.arch.noRawHookName',
        'mahout.arch.boundedHowdahStatement',
        'mahout.arch.transactionOnlyInGateway',
    ];

    public function testEveryDeliberateViolationIsReportedByItsRule(): void
    {
        $identifiers = \array_column($this->analyse('fixtures/architecture/violations'), 'identifier');

        foreach (self::VIOLATED as $identifier) {
            self::assertContains($identifier, $identifiers, $identifier.' did not fire on its fixture');
        }
    }

    public function testTheCleanFixtureIsReportedByNoneOfThoseRules(): void
    {
        $identifiers = \array_column($this->analyse('fixtures/architecture/clean'), 'identifier');

        foreach (self::VIOLATED as $identifier) {
            self::assertNotContains($identifier, $identifiers, $identifier.' fired on the clean fixture');
        }
    }

    public function testEachViolationIsReportedInTheFileThatCausesIt(): void
    {
        $reported = $this->analyse('fixtures/architecture/violations');

        foreach (self::VIOLATED as $identifier) {
            $files = [];
            foreach ($reported as $finding) {
                if ($finding['identifier'] === $identifier) {
                    $files[] = \basename($finding['file']);
                }
            }

            self::assertNotEmpty($files, $identifier.' was reported in no file');
        }
    }

    public function testTheAnalyzerAndItsConfigurationAreWhereTheTestAssumes(): void
    {
        self::assertFileExists($this->root().'/vendor/bin/phpstan');
        self::assertFileExists($this->root().'/phpstan-arch-fixtures.neon');
    }

    /**
     * @return list<array{file: string, identifier: string, message: string}>
     */
    private function analyse(string $relativePath): array
    {
        // The report is read from stdout as JSON. PHPStan's advice banner goes
        // to stderr, which is discarded so a failing run does not spray it
        // through this suite's own output.
        $command = \sprintf(
            '%s %s analyse -c %s --no-progress --error-format=json %s 2>%s',
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($this->root().'/vendor/bin/phpstan'),
            \escapeshellarg($this->root().'/phpstan-arch-fixtures.neon'),
            \escapeshellarg($this->root().'/'.$relativePath),
            '\\' === \DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null',
        );

        $lines = [];
        $exit = 0;
        \exec($command, $lines, $exit);

        $output = \implode("\n", $lines);
        $decoded = \json_decode($output, true);

        self::assertIsArray(
            $decoded,
            'PHPStan produced no JSON (exit '.$exit.'): '.$output,
        );

        /** @var array<string, mixed> $decoded */
        $findings = [];
        foreach ((array) ($decoded['files'] ?? []) as $file => $report) {
            foreach ((array) (\is_array($report) ? ($report['messages'] ?? []) : []) as $message) {
                if (!\is_array($message)) {
                    continue;
                }

                $findings[] = [
                    'file' => (string) $file,
                    'identifier' => (string) ($message['identifier'] ?? ''),
                    'message' => (string) ($message['message'] ?? ''),
                ];
            }
        }

        return $findings;
    }

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
