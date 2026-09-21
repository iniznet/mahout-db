<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

use Iniznet\Mahout\Db\Contracts\MigrationStore;
use Iniznet\Mahout\Db\Contracts\OrphanSource;
use Iniznet\Mahout\Db\Contracts\SchemaVersionStore;
use Iniznet\Mahout\Db\Contracts\SearchIndexPresence;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Contracts\SweepCursor;
use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Db\Exception\InvalidMigrationList;
use Iniznet\Mahout\Db\Exception\InvalidOrphanSourceList;
use Iniznet\Mahout\Db\Exception\InvalidSchemaVersion;
use Iniznet\Mahout\Db\Internal\OptionSearchIndexPresence;
use Iniznet\Mahout\Db\Internal\OptionSweepCursor;
use Iniznet\Mahout\Db\Internal\WordPressSchemaVersionStore;
use Iniznet\Mahout\Db\Internal\WpdbConnection;
use Iniznet\Mahout\Db\Internal\WpdbMigrationStore;
use Iniznet\Mahout\Db\Internal\WpdbTableGateway;
use Iniznet\Mahout\Kernel\Container;
use Iniznet\Mahout\Kernel\Contracts\ServiceProvider;
use Iniznet\Mahout\Kernel\Diagnostics;

/**
 * The composition-root entry point for mahout-db.
 *
 * A provider is instantiated by class name with no constructor arguments, so
 * the connection is WordPress's own and is read once here: the same shape as
 * Kernel::inWordPress(). Every collaborator the provider declares is declared
 * under the Contracts interface a consumer depends on, and every lookup below
 * resolves by that interface, so nothing outside this package names an Internal
 * class to receive one.
 *
 * It attaches exactly two actions, and they are the only two run paths:
 * after_switch_theme at priority 10, and admin_init at priority 20. Nothing is
 * attached to a front-end hook, to admin-ajax.php or to cron, because a
 * migration on an anonymous request is a schema change nobody asked for.
 *
 * The lazy path reads one option first -- the stored schema version compared to
 * the code constant -- so a request whose schema is current never touches the
 * ledger at all.
 */
final class DbProvider implements ServiceProvider
{
    /** The code's schema version. Filters through mahout/db/schema_version. */
    private const int SCHEMA_VERSION = 1;

    private const int THEME_SWITCH_PRIORITY = 10;

    private const int LAZY_PRIORITY = 20;

    private const int ORPHAN_PRIORITY = 20;

    public function register(Container $container): void
    {
        $connection = WpdbConnection::inWordPress();
        $emitter = new DdlEmitter();
        $ledger = MigrationLedgerSchema::table($connection->prefix(), $connection->charsetCollate());

        // Declared by contract: the key is the interface the consumer's
        // constructor names, not the implementation class it may not depend on.
        $container->set(service: $connection, id: SqlConnection::class);
        $container->set(service: new WpdbMigrationStore($connection, $emitter, $ledger), id: MigrationStore::class);
        $container->set(service: new WordPressSchemaVersionStore(), id: SchemaVersionStore::class);
        $container->set(service: new WpdbTableGateway($connection), id: TableGateway::class);
        $container->set(
            service: new OptionSearchIndexPresence($connection, SearchIndex::onPosts($connection->prefix())),
            id: SearchIndexPresence::class,
        );
        $container->set(service: new OptionSweepCursor(), id: SweepCursor::class);
        $container->set($emitter);
    }

    public function boot(Container $container): void
    {
        $migrations = MigrationList::fromHookPayload($this->filteredMigrations());
        $container->set($migrations);

        $runner = new MigrationRunner(
            ledger: $container->get(MigrationStore::class),
            versions: $container->get(SchemaVersionStore::class),
            migrations: $migrations,
            codeVersion: $this->codeVersion(),
            diagnostics: $container->get(Diagnostics::class),
        );
        $container->set($runner);

        $this->attachThemeSwitch($runner);
        $this->attachLazy($runner);
        $this->attachSearchIndexRefresh($container->get(SearchIndexPresence::class));
        $this->attachOrphans($container);
    }

    /**
     * The presence option is invalidated once per migration, off a request path.
     */
    private function attachSearchIndexRefresh(SearchIndexPresence $presence): void
    {
        \add_action(
            Hooks::AFTER_MIGRATE,
            static function () use ($presence): void {
                $presence->refresh();
            },
            priority: self::ORPHAN_PRIORITY,
            accepted_args: 0,
        );
    }

    /**
     * The two orphan paths: the keyed delete on a post deletion, and the
     * chunked sweep on this package's own action. Scheduling the sweep is the
     * theme's job; it never runs on a request path.
     */
    private function attachOrphans(Container $container): void
    {
        $gateway = $container->get(TableGateway::class);
        $diagnostics = $container->get(Diagnostics::class);
        $sweep = new OrphanSweep($gateway, $container->get(SweepCursor::class), $diagnostics);
        $collector = new OrphanCollector($gateway, $diagnostics);

        \add_action(
            Hooks::DELETED_POST,
            static function (int $postId) use ($collector): void {
                $collector->forPost($postId, self::orphanSources());
            },
            priority: self::ORPHAN_PRIORITY,
            accepted_args: 1,
        );

        \add_action(
            Hooks::GC,
            static function () use ($sweep): void {
                foreach (self::orphanSources() as $source) {
                    $sweep->sweep($source);
                }
            },
            priority: self::ORPHAN_PRIORITY,
            accepted_args: 0,
        );
    }

    /**
     * @return list<OrphanSource>
     */
    private static function orphanSources(): array
    {
        $declared = \apply_filters(Hooks::ORPHAN_SOURCES, []);

        if (!\is_array($declared)) {
            throw InvalidOrphanSourceList::notAList(Hooks::ORPHAN_SOURCES);
        }

        $sources = [];
        foreach ($declared as $source) {
            if (!$source instanceof OrphanSource) {
                throw InvalidOrphanSourceList::notASource(Hooks::ORPHAN_SOURCES);
            }

            $sources[] = $source;
        }

        return $sources;
    }

    /**
     * The theme-switch path: where a first install creates its tables.
     */
    private function attachThemeSwitch(MigrationRunner $runner): void
    {
        \add_action(
            Hooks::AFTER_SWITCH_THEME,
            static function () use ($runner): void {
                $runner->migrate();
            },
            priority: self::THEME_SWITCH_PRIORITY,
            accepted_args: 0,
        );
    }

    /**
     * The lazy path: the fallback for a deployment that copied files without
     * running the CLI. It is skipped when the stored version already matches, and
     * it is refused for an incapable user, for AJAX and for cron.
     */
    private function attachLazy(MigrationRunner $runner): void
    {
        \add_action(
            Hooks::ADMIN_INIT,
            static function () use ($runner): void {
                $context = RunContext::lazy(
                    manageOptions: \current_user_can(Capabilities::ManageOptions->value),
                    ajax: \wp_doing_ajax(),
                    cron: \wp_doing_cron(),
                );

                if (!$context->permitted()) {
                    return;
                }

                if (!$runner->schemaVersion()->pending()) {
                    return;
                }

                $runner->migrate();
            },
            priority: self::LAZY_PRIORITY,
            accepted_args: 0,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function filteredMigrations(): array
    {
        $filtered = \apply_filters(Hooks::MIGRATIONS, []);

        if (!\is_array($filtered)) {
            throw InvalidMigrationList::notAList(Hooks::MIGRATIONS);
        }

        return $filtered;
    }

    private function codeVersion(): int
    {
        $version = \apply_filters(Hooks::SCHEMA_VERSION, self::SCHEMA_VERSION);

        if (!\is_int($version)) {
            throw InvalidSchemaVersion::notAnInteger(Hooks::SCHEMA_VERSION);
        }

        return $version;
    }
}
