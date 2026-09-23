<?php

/**
 * The search term, tokenised. A visitor-supplied string never reaches
 * AGAINST raw: the tokeniser keeps word runs only, so no boolean operator,
 * quote, wildcard or punctuation survives it, and natural language mode
 * treats every token as an ordinary word. The caps are enforced before any
 * query is built -- at most eight tokens, each at most 100 bytes -- and a
 * term whose every token is shorter than the index's minimum renders the
 * empty state instead of falling back to a scan.
 */

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Search;

final readonly class SearchTerms
{
    public const int MAX_TOKENS = 8;
    public const int MAX_TOKEN_BYTES = 100;
    public const int MIN_TOKEN_SIZE = 3;

    /**
     * @param list<string> $tokens
     */
    private function __construct(
        public string $raw,
        private array $tokens,
    ) {
    }

    public static function fromString(string $term): self
    {
        $matches = [];
        $found = \preg_match_all('/[\p{L}\p{N}]+/u', $term, $matches);

        $tokens = [];

        if (\is_int($found) && $found > 0) {
            foreach ($matches[0] as $token) {
                if (\strlen($token) > self::MAX_TOKEN_BYTES) {
                    continue;
                }

                if (\mb_strlen($token) < self::MIN_TOKEN_SIZE) {
                    continue;
                }

                $tokens[] = $token;

                if (self::MAX_TOKENS === \count($tokens)) {
                    break;
                }
            }
        }

        return new self($term, $tokens);
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    public function hasTokens(): bool
    {
        return [] !== $this->tokens;
    }

    /** The joined token list the MATCH clause carries: plain words, one string. */
    public function forMatch(): string
    {
        return \implode(' ', $this->tokens);
    }
}
