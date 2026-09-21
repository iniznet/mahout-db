<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * The facts that decide whether a run path may proceed.
 *
 * It is a value object rather than a set of global reads so the refusal branch
 * is provable without changing the running site: the explicit and the
 * theme-switch paths are always permitted, and the lazy path is permitted only
 * for a capable user, on a request that is neither AJAX nor cron.
 */
final readonly class RunContext
{
    public function __construct(
        public RunPath $path,
        public bool $manageOptions,
        public bool $ajax,
        public bool $cron,
    ) {
    }

    public static function explicit(): self
    {
        return new self(RunPath::Explicit, false, false, false);
    }

    public static function themeSwitch(): self
    {
        return new self(RunPath::ThemeSwitch, false, false, false);
    }

    public static function lazy(bool $manageOptions, bool $ajax, bool $cron): self
    {
        return new self(RunPath::Lazy, $manageOptions, $ajax, $cron);
    }

    /**
     * The reasons the path is refused. Empty when it is permitted.
     *
     * @return list<string>
     */
    public function refusals(): array
    {
        if (RunPath::Lazy !== $this->path) {
            return [];
        }

        $refusals = [];

        if (!$this->manageOptions) {
            $refusals[] = 'the current user cannot manage options';
        }
        if ($this->ajax) {
            $refusals[] = 'the request is an AJAX request';
        }
        if ($this->cron) {
            $refusals[] = 'the request is a cron request';
        }

        return $refusals;
    }

    public function permitted(): bool
    {
        return [] === $this->refusals();
    }
}
