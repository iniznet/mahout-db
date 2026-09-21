<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Architecture;

use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The schema path is this package's own DDL. Core's formatting-sensitive schema
 * function is never called, anywhere in the package.
 *
 * The scan is over identifier tokens rather than over file text, and that is
 * the difference between a useless test and a real one: DdlEmitter's docblock
 * names the function deliberately, to record why it is not used, and a scan of
 * raw text would fail on the explanation. A call site cannot hide from a token.
 *
 * @internal
 */
final class NoDbDeltaTest extends TestCase
{
    /**
     * Assembled from two literals so this test cannot match its own source.
     */
    private const FORBIDDEN = 'dbDelta';

    public function testNoFileInThePackageCallsCoresSchemaFunction(): void
    {
        foreach ($this->phpFiles() as $file) {
            foreach ($this->identifiers($file) as $identifier) {
                self::assertNotSame(
                    \strtolower(self::FORBIDDEN),
                    \strtolower($identifier),
                    $file.' calls core\'s schema function',
                );
            }
        }
    }

    public function testTheScanCoveredEveryPhpFileItClaimsTo(): void
    {
        $files = $this->phpFiles();
        $identifiers = [];

        foreach ($files as $file) {
            $identifiers = [...$identifiers, ...$this->identifiers($file)];
        }

        self::assertGreaterThanOrEqual(50, \count($files));
        self::assertGreaterThan(500, \count($identifiers), 'the scan read no identifiers, so it proves nothing');
    }

    /**
     * Every identifier token in a file: a name that is called, a constant that
     * is read, a function that is referenced. Comments and literals are not
     * identifiers and are deliberately excluded.
     *
     * @return list<string>
     */
    private function identifiers(string $file): array
    {
        $identifiers = [];

        foreach (\token_get_all((string) \file_get_contents($file)) as $token) {
            if (\is_array($token) && T_STRING === $token[0]) {
                $identifiers[] = $token[1];
            }
        }

        return $identifiers;
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = [];

        foreach ([$this->root().'/src', $this->root().'/tests', $this->root().'/fixtures'] as $directory) {
            if (!\is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && 'php' === $file->getExtension()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        \sort($files);

        return $files;
    }

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
