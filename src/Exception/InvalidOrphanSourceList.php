<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Exception;

/**
 * The mahout/db/orphan_sources filter returned something other than a list of
 * OrphanSource values.
 */
final class InvalidOrphanSourceList extends \UnexpectedValueException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $hook,
    ) {
        parent::__construct($message);
    }

    public static function notAList(string $hook): self
    {
        return new self(
            \sprintf('The filter "%s" must return an array of orphan sources.', $hook),
            $hook,
        );
    }

    public static function notASource(string $hook): self
    {
        return new self(
            \sprintf('The filter "%s" returned a value that is not an OrphanSource.', $hook),
            $hook,
        );
    }

    public function hook(): string
    {
        return $this->hook;
    }
}
