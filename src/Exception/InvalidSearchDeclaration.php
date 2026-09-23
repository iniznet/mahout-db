<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * A query declared the indexed search path and did not carry what the
 * declaration requires.
 *
 * A declared search swaps its clause into core's query. The swapped value is
 * therefore read from a query var, and a declaration that carries no clause is
 * a bug that would silently return every published post instead of the search
 * results -- so it is refused rather than degraded.
 */
final class InvalidSearchDeclaration extends \RuntimeException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $queryVar,
        private readonly string $found,
    ) {
        parent::__construct($message);
    }

    public static function absent(string $queryVar): self
    {
        return new self(
            \sprintf('A declared indexed search carries no "%s" query var; the clause is never guessed.', $queryVar),
            $queryVar,
            'absent',
        );
    }

    public static function empty(string $queryVar): self
    {
        return new self(
            \sprintf('The "%s" query var of a declared indexed search is empty; an empty clause is not a search.', $queryVar),
            $queryVar,
            'empty',
        );
    }

    public static function notAString(string $queryVar, string $found): self
    {
        return new self(
            \sprintf('The "%s" query var of a declared indexed search is %s, not a string.', $queryVar, $found),
            $queryVar,
            $found,
        );
    }

    /** The query var that failed the declaration. */
    public function queryVar(): string
    {
        return $this->queryVar;
    }

    /** What was found where the clause or the ordering belongs. */
    public function found(): string
    {
        return $this->found;
    }
}
