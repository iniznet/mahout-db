<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * A search term carries no token the FULLTEXT index can answer with: every
 * run of word characters is shorter than the index's minimum token length, or
 * the term has no word characters at all.
 *
 * The tokeniser's contract is that an unusable term renders the empty state
 * rather than falling back to a scan, so no clause is ever built from it. This
 * refusal is the floor under that contract: a caller that ignores the token
 * list and asks for a clause anyway is told, not degraded.
 */
final class UnusableSearchTerm extends \InvalidArgumentException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $term,
    ) {
        parent::__construct($message);
    }

    public static function withoutTokens(string $term = ''): self
    {
        return new self(
            'The search term has no token the index can match; its empty state is rendered instead of a scan.',
            $term,
        );
    }

    /** The term that produced no usable token, for a caller's own report. */
    public function term(): string
    {
        return $this->term;
    }
}
