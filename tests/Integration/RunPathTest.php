<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Integration;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\DbProvider;
use Iniznet\Mahout\Db\Exception\InvalidMigrationList;
use Iniznet\Mahout\Db\Exception\InvalidSchemaVersion;
use Iniznet\Mahout\Db\Hooks;
use Iniznet\Mahout\Db\Tests\Fixtures\FixtureSet;
use Iniznet\Mahout\Db\Tests\TestCase;
use Iniznet\Mahout\Kernel\Container;

/**
 * The run paths, exercised through the provider rather than around it.
 *
 * The negative claims are made against wpdb's own statement buffer, so no fake
 * connection is involved: what the provider did or did not send to the database
 * is the evidence.
 *
 * @internal
 */
final class RunPathTest extends TestCase
{
    public function testTheProviderAttachesExactlyTwoRunPathsAndNoFrontEndOne(): void
    {
        $before = $this->hookTotals();

        // attachProvider() and not bootProvider(): quieting core's own callbacks
        // removes hooks, and this test counts hooks. The two are separate for
        // exactly that reason.
        $this->attachProvider();

        $after = $this->hookTotals();

        self::assertSame(1, $after['after_switch_theme'] - $before['after_switch_theme']);
        self::assertSame(1, $after['admin_init'] - $before['admin_init']);

        foreach (['init', 'wp_loaded', 'template_redirect', 'send_headers', 'wp_ajax_mahout', 'wp_ajax_nopriv_mahout', 'wp_cron', 'rest_api_init', 'shutdown'] as $tag) {
            self::assertSame(0, $after[$tag] - $before[$tag], $tag.' gained a callback');
        }
    }

    public function testAFrontEndRequestRunsNothingAtAll(): void
    {
        $this->bootProvider($this->fixtures([FixtureSet::VALUE], $this->connection()));
        $cursor = $this->statementCount();

        \do_action('init');
        \do_action('wp_loaded');
        \do_action('template_redirect');
        \do_action('send_headers');

        // 'shutdown' is deliberately not fired: core's wp_ob_end_flush_all is
        // attached to it, and ending PHPUnit's own output buffer turns a
        // green suite into "headers already sent" everywhere downstream.

        self::assertSame([], $this->ledgerStatementsSince($cursor));
        self::assertFalse($this->tableExists($this->ledgerName()));
        self::assertFalse($this->tableExists($this->valueTable()));
    }

    public function testTheThemeSwitchPathAppliesEveryPendingMigrationAndRecordsTheVersion(): void
    {
        $connection = $this->connection();
        $this->bootProvider($this->fixtures([FixtureSet::VALUE, FixtureSet::META], $connection));

        \do_action('after_switch_theme');

        self::assertSame(
            ['fixture/0001_create_value_table', 'fixture/0002_create_meta_table'],
            $this->ledgerStore($connection)->applied(),
        );
        self::assertTrue($this->tableExists($this->valueTable()));
        self::assertTrue($this->tableExists($this->metaTable()));
        self::assertSame(self::CODE_VERSION, (int) \get_option(self::LEDGER_OPTION, 0));
    }

    public function testTheThemeSwitchPathIsIdempotent(): void
    {
        $connection = $this->connection();
        $this->bootProvider($this->fixtures([FixtureSet::VALUE], $connection));

        \do_action('after_switch_theme');
        $cursor = $this->statementCount();
        \do_action('after_switch_theme');

        // The claim of idempotence is that no migration was applied a second
        // time. A re-statement of the ledger bootstrap is allowed: it is
        // CREATE TABLE IF NOT EXISTS, and install() runs before the pending set
        // is read.
        foreach ($this->statementsSince($cursor) as $statement) {
            self::assertStringNotContainsString('INSERT INTO', $statement);
        }

        self::assertCount(1, $this->ledgerStore($connection)->applied());
        self::assertSame(1, $this->ledgerStore($connection)->latestBatch());
    }

    public function testTheLazyPathAppliesWhenTheStoredVersionIsBehind(): void
    {
        $connection = $this->connection();
        $this->bootProvider($this->fixtures([FixtureSet::VALUE], $connection));
        $this->admin();
        $cursor = $this->statementCount();

        \do_action('admin_init');

        self::assertNotSame([], $this->ledgerStatementsSince($cursor));
        self::assertTrue($this->tableExists($this->valueTable()));
        self::assertSame(['fixture/0001_create_value_table'], $this->ledgerStore($connection)->applied());
    }

    public function testTheLazyPathTouchesNothingWhenTheStoredVersionAlreadyMatches(): void
    {
        $connection = $this->connection();
        $this->runner($this->fixtures([FixtureSet::VALUE], $connection), $connection)->migrate();
        $this->bootProvider($this->fixtures([FixtureSet::VALUE], $connection));
        $this->admin();
        $cursor = $this->statementCount();

        \do_action('admin_init');

        self::assertSame([], $this->ledgerStatementsSince($cursor), 'the option gate must keep the request off the ledger entirely');
    }

    public function testTheLazyPathIsRefusedForAUserWhoCannotManageOptions(): void
    {
        $connection = $this->connection();
        $this->bootProvider($this->fixtures([FixtureSet::VALUE], $connection));
        \wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $cursor = $this->statementCount();

        \do_action('admin_init');

        self::assertFalse(\current_user_can('manage_options'));
        self::assertSame([], $this->ledgerStatementsSince($cursor));
        self::assertFalse($this->tableExists($this->ledgerName()));
    }

    public function testTheLazyPathIsRefusedOnAnAjaxRequest(): void
    {
        $connection = $this->connection();
        $this->bootProvider($this->fixtures([FixtureSet::VALUE], $connection));
        $this->admin();
        \add_filter('wp_doing_ajax', '__return_true');
        $cursor = $this->statementCount();

        \do_action('admin_init');

        self::assertSame([], $this->ledgerStatementsSince($cursor));
        self::assertFalse($this->tableExists($this->ledgerName()));
    }

    public function testTheLazyPathIsRefusedOnACronRequest(): void
    {
        $connection = $this->connection();
        $this->bootProvider($this->fixtures([FixtureSet::VALUE], $connection));
        $this->admin();
        \add_filter('wp_doing_cron', '__return_true');
        $cursor = $this->statementCount();

        \do_action('admin_init');

        self::assertSame([], $this->ledgerStatementsSince($cursor));
        self::assertFalse($this->tableExists($this->ledgerName()));
    }

    public function testTheMigrationsFilterSuppliesTheRegisteredSet(): void
    {
        $connection = $this->connection();
        $this->bootProvider($this->fixtures([FixtureSet::VALUE], $connection));

        \do_action('after_switch_theme');

        self::assertSame(['fixture/0001_create_value_table'], $this->ledgerStore($connection)->applied());
    }

    public function testAMigrationsFilterThatDoesNotReturnAListStopsTheBoot(): void
    {
        \add_filter(Hooks::MIGRATIONS, static fn (): string => 'not a list', accepted_args: 1);

        $this->expectException(InvalidMigrationList::class);

        $this->bootProvider();
    }

    public function testASchemaVersionFilterThatDoesNotReturnAnIntegerStopsTheBoot(): void
    {
        \add_filter(Hooks::SCHEMA_VERSION, static fn (): string => '1.4', accepted_args: 1);

        $this->expectException(InvalidSchemaVersion::class);

        $this->bootProvider();
    }

    private function bootProvider(array $migrations = []): void
    {
        $this->attachProvider($migrations);
        $this->quietCoreCallbacks();
    }

    /**
     * @param list<Migration> $migrations
     */
    private function attachProvider(array $migrations = []): void
    {
        if ([] !== $migrations) {
            \add_filter(
                Hooks::MIGRATIONS,
                static fn (array $declared): array => [...$declared, ...$migrations],
                accepted_args: 1,
            );
        }

        $container = new Container();
        $container->set($this->diagnostics());

        $provider = new DbProvider();
        $provider->register($container);
        $provider->boot($container);
    }

    /**
     * Core's own admin_init and template_redirect callbacks reach out to
     * WordPress.org, send real HTTP headers and redirect. None of that is this
     * package's behaviour, and inside a PHPUnit process it is impossible: the
     * printer has already written to stdout, so a header() call is a failure
     * that says nothing about mahout-db.
     */
    private function quietCoreCallbacks(): void
    {
        foreach ([
            '_maybe_update_core',
            '_maybe_update_plugins',
            '_maybe_update_themes',
            'wp_admin_headers',
            'send_frame_options_header',
        ] as $callback) {
            \remove_action('admin_init', $callback);
        }

        // Core's suggested privacy policy content refuses to run outside a
        // real admin request, and firing admin_init here is not one. The
        // notice is core policing its own precondition, not this package.
        \remove_action('admin_init', ['WP_Privacy_Policy_Content', 'add_suggested_content'], 1);
        \remove_action('admin_init', ['WP_Privacy_Policy_Content', 'text_change_check'], 100);

        \remove_action('template_redirect', 'redirect_canonical');
    }

    private function admin(): void
    {
        \wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        self::assertTrue(\current_user_can('manage_options'));
    }

    /**
     * @return array<string, int>
     */
    private function hookTotals(): array
    {
        $totals = [];
        foreach ([
            'after_switch_theme', 'admin_init', 'init', 'wp_loaded', 'template_redirect',
            'send_headers', 'wp_ajax_mahout', 'wp_ajax_nopriv_mahout', 'wp_cron', 'rest_api_init', 'shutdown',
        ] as $tag) {
            $totals[$tag] = $this->hookCount($tag);
        }

        return $totals;
    }
}
