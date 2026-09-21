<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Unit;

use Iniznet\Mahout\Db\RunContext;
use Iniznet\Mahout\Db\RunPath;
use Iniznet\Mahout\Db\Tests\TestCase;

/**
 * The gate on each run path.
 *
 * The refusal branch is provable without changing the running site, which is
 * why RunContext is a value object rather than a set of global reads.
 *
 * @internal
 */
final class RunContextTest extends TestCase
{
    public function testTheExplicitPathIsAlwaysPermitted(): void
    {
        $context = RunContext::explicit();

        self::assertSame(RunPath::Explicit, $context->path);
        self::assertTrue($context->permitted());
        self::assertSame([], $context->refusals());
    }

    public function testTheThemeSwitchPathIsAlwaysPermitted(): void
    {
        $context = RunContext::themeSwitch();

        self::assertSame(RunPath::ThemeSwitch, $context->path);
        self::assertTrue($context->permitted());
    }

    public function testTheLazyPathIsPermittedForACapableUserOnANormalRequest(): void
    {
        $context = RunContext::lazy(manageOptions: true, ajax: false, cron: false);

        self::assertSame(RunPath::Lazy, $context->path);
        self::assertTrue($context->permitted());
        self::assertSame([], $context->refusals());
    }

    public function testTheLazyPathIsRefusedForAnIncapableUser(): void
    {
        $context = RunContext::lazy(manageOptions: false, ajax: false, cron: false);

        self::assertFalse($context->permitted());
        self::assertCount(1, $context->refusals());
        self::assertStringContainsString('manage options', $context->refusals()[0]);
    }

    public function testTheLazyPathIsRefusedUnderAjax(): void
    {
        $context = RunContext::lazy(manageOptions: true, ajax: true, cron: false);

        self::assertFalse($context->permitted());
        self::assertStringContainsString('AJAX', $context->refusals()[0]);
    }

    public function testTheLazyPathIsRefusedUnderCron(): void
    {
        $context = RunContext::lazy(manageOptions: true, ajax: false, cron: true);

        self::assertFalse($context->permitted());
        self::assertStringContainsString('cron', $context->refusals()[0]);
    }

    public function testEveryReasonIsReportedSoAProblemIsNotFoundOneAtATime(): void
    {
        $context = RunContext::lazy(manageOptions: false, ajax: true, cron: true);

        self::assertCount(3, $context->refusals());
        self::assertFalse($context->permitted());
    }
}
