<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests;

use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Internal\LegacyNameAdoption;
use Iniznet\Mahout\Db\Internal\WordPressSchemaVersionStore;
use Iniznet\Mahout\Db\Internal\WpdbConnection;
use Iniznet\Mahout\Db\Internal\WpdbMigrationStore;
use Iniznet\Mahout\Db\MigrationLedgerSchema;
use Iniznet\Mahout\Db\MigrationList;
use Iniznet\Mahout\Db\MigrationRunner;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Db\Tests\Fixtures\FixtureSet;
use Iniznet\Mahout\Db\Tests\Fixtures\NotesTable;
use Iniznet\Mahout\Db\Tests\Fixtures\RecordingConnection;
use Iniznet\Mahout\Kernel\Container;
use Iniznet\Mahout\Kernel\Contracts\QuerySource;
use Iniznet\Mahout\Kernel\Diagnostics;
use Iniznet\Mahout\Kernel\Environment;
use Iniznet\Mahout\Kernel\RuntimeIdentity;

/**
 * The base test case for this package.
 *
 * Core's WP_UnitTestCase already wraps each test in a transaction and provides
 * the factories. This class adds the three things the required tests need: the
 * InnoDB assertion that runs before any test, a runner wired to the test
 * database, and a cleanup that drops the tables a test created (DDL implicitly
 * commits, so the per-test transaction cannot do it).
 *
 * @internal
 */
abstract class TestCase extends \WP_UnitTestCase
{
    /**
     * The option names this package wrote before a host declared an identity.
     *
     * They are history, not a namespace to avoid: LegacyNameAdoptionTest moves an
     * installed site off them, and the suite deletes them so an adoption test that
     * seeds one cannot poison whichever test runs next.
     *
     * @var list<string>
     */
    public const array LEGACY_OPTIONS = [
        'mahout_db_schema_version',
        'mahout_db_search_index',
        'mahout_db_sweep_cursors',
    ];

    public static function ledgerOption(): string
    {
        return self::identity()->namespacedName('db_schema_version');
    }

    public static function searchOption(): string
    {
        return self::identity()->namespacedName('db_search_index');
    }

    public static function sweepOption(): string
    {
        return self::identity()->namespacedName('db_sweep_cursors');
    }

    /**
     * @return list<string>
     */
    public static function everyOptionName(): array
    {
        return [...self::LEGACY_OPTIONS, self::ledgerOption(), self::searchOption(), self::sweepOption()];
    }

    protected static function clearOptions(): void
    {
        foreach (self::everyOptionName() as $option) {
            \delete_option($option);
        }
    }

    public const int CODE_VERSION = 1;

    private static bool $engineAsserted = false;

    /** @var list<string> */
    private array $createdTables = [];

    /**
     * Core's per-test query filter (start_transaction()) rewrites every DROP
     * TABLE into DROP TEMPORARY TABLE, which silently no-ops against a real
     * table. A schema leftover from a process outside this suite -- a CLI run,
     * a crashed phpunit, another package's manual step against this shared
     * test database -- therefore survives every per-test cleanup and makes the
     * suite's absence assertions lie. setUp() and tearDown() run inside the
     * filter's window; setUpBeforeClass() runs before it attaches, so the
     * drops here are real. Once per class, outside the per-test transaction,
     * which is where the database strategy places class-level schema work.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        foreach (self::declaredTables() as $table) {
            self::dropTable($table);
        }

        self::dropSearchIndex();
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::assertConnectionIsInnoDB();

        // The gate option is cleared before the test body as well as after it:
        // DDL implicitly commits, so core's per-test transaction cannot be
        // relied on to undo an option the previous test's migrations wrote.
        self::clearOptions();
        $this->dropTable($this->ledgerName());
    }

    protected function tearDown(): void
    {
        // The option is deleted BEFORE any DDL runs, because DDL implicitly
        // commits: a delete issued after the drop would happen inside the fresh
        // transaction that core's tearDown() then rolls back, leaving the value
        // this test wrote behind for the next one.
        self::clearOptions();
        self::dropSearchIndex();
        $this->dropCreatedTables();

        parent::tearDown();
    }

    /**
     * The search index tests add FULLTEXT keys to core's own posts table; DDL
     * implicitly commits, so the per-test transaction cannot undo them.
     *
     * Every FULLTEXT key goes, whichever name it carries. Dropping one known
     * name is what let a test that created an index under a different name poison
     * whichever test ran next: the rename tests deliberately hold a legacy name
     * and a neighbour's name, and no suite may depend on the order it runs in.
     */
    protected static function dropSearchIndex(): void
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results(
            'SHOW INDEX FROM '.$wpdb->posts." WHERE Index_type = 'FULLTEXT'",
            ARRAY_A,
        );

        foreach (array_unique(array_column($rows, 'Key_name')) as $name) {
            $wpdb->query('ALTER TABLE '.$wpdb->posts.' DROP INDEX `'.$name.'`');
        }
    }

    /**
     * Deliverable 9: the connection's storage engine is asserted before any
     * transactional test runs. A non-transactional engine makes every
     * transaction assertion in this suite decorative.
     */
    private static function assertConnectionIsInnoDB(): void
    {
        if (self::$engineAsserted) {
            return;
        }

        self::$engineAsserted = true;

        global $wpdb;

        self::assertSame(
            'InnoDB',
            $wpdb->get_var('SELECT @@default_storage_engine'),
            'The test database must report InnoDB; a transaction assertion against another engine asserts nothing.',
        );
    }

    protected function prefix(): string
    {
        global $wpdb;

        return (string) $wpdb->prefix;
    }

    protected function charsetCollate(): string
    {
        global $wpdb;

        return (string) $wpdb->get_charset_collate();
    }

    /**
     * Declares the identity a host would declare in its composition root. DbProvider
     * refuses to register without it, so any test that boots the provider calls
     * this first -- the same line a consumer's Bootstrap.php carries.
     */
    protected function declareIdentity(Container $container): Container
    {
        $container->set(self::identity(), RuntimeIdentity::class);

        return $container;
    }

    /** The identity the suite declares, standing in for a host's own. */
    protected static function identity(): RuntimeIdentity
    {
        return RuntimeIdentity::fromSlug('suite');
    }

    protected function ledger(): Table
    {
        return MigrationLedgerSchema::table($this->prefix(), self::identity(), $this->charsetCollate());
    }

    protected function ledgerName(): string
    {
        return $this->ledger()->name->value;
    }

    protected function connection(): WpdbConnection
    {
        global $wpdb;

        return new WpdbConnection($wpdb);
    }

    protected function recording(?SqlConnection $inner = null): RecordingConnection
    {
        return new RecordingConnection($inner ?? $this->connection());
    }

    protected function ledgerStore(SqlConnection $connection): WpdbMigrationStore
    {
        return new WpdbMigrationStore($connection, new DdlEmitter(), $this->ledger());
    }

    protected function valueTable(): string
    {
        return NotesTable::nameFor($this->prefix())->value;
    }

    protected function metaTable(): string
    {
        return Identifier::prefixed($this->prefix(), 'fixture_meta')->value;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<\Iniznet\Mahout\Db\Contracts\Migration>
     */
    protected function fixtures(array $keys, SqlConnection $connection): array
    {
        return FixtureSet::of($keys, $connection, $this->prefix(), $this->charsetCollate());
    }

    protected function diagnostics(): Diagnostics
    {
        return new Diagnostics(
            environment: new Environment(type: 'production', debug: false, developmentMode: false),
            queries: new RecordingQuerySource(),
        );
    }

    protected function notesTable(?SqlConnection $connection = null): NotesTable
    {
        return new NotesTable(
            emitter: new DdlEmitter(),
            name: NotesTable::nameFor($this->prefix()),
            charsetCollate: $this->charsetCollate(),
        );
    }

    /**
     * @param list<\Iniznet\Mahout\Db\Contracts\Migration> $migrations
     */
    protected function runner(array $migrations, SqlConnection $connection, ?Diagnostics $diagnostics = null): MigrationRunner
    {
        return new MigrationRunner(
            ledger: $this->ledgerStore($connection),
            versions: new WordPressSchemaVersionStore(self::identity()),
            legacyNames: new LegacyNameAdoption($connection, new DdlEmitter(), self::identity()),
            migrations: MigrationList::fromHookPayload($migrations),
            codeVersion: self::CODE_VERSION,
            diagnostics: $diagnostics ?? $this->diagnostics(),
        );
    }

    /**
     * Every statement wpdb ran since the start of the process, in order.
     *
     * SAVEQUERIES is defined by the bootstrap, so the run-path tests can prove
     * "the lazy gate wrote nothing" against the real connection instead of a
     * decorator the provider would not have accepted anyway.
     *
     * @return list<string>
     */
    protected function statements(): array
    {
        global $wpdb;

        $statements = [];
        foreach ((array) $wpdb->queries as $entry) {
            $statements[] = \is_array($entry) ? (string) ($entry[0] ?? '') : (string) $entry;
        }

        return $statements;
    }

    protected function statementCount(): int
    {
        return \count($this->statements());
    }

    /**
     * @return list<string>
     */
    protected function statementsSince(int $cursor): array
    {
        return \array_slice($this->statements(), $cursor);
    }

    /**
     * The statements that touched the ledger, which is what a run path is
     * proved to have avoided.
     *
     * @return list<string>
     */
    protected function ledgerStatementsSince(int $cursor): array
    {
        return \array_values(\array_filter(
            $this->statementsSince($cursor),
            static fn (string $statement): bool => str_contains($statement, 'mahout_migrations'),
        ));
    }

    /**
     * How many callbacks are attached to a hook. The run-path proof reads it
     * around boot() to show which hooks the provider touched and which it did not.
     */
    protected function hookCount(string $tag): int
    {
        $hook = $GLOBALS['wp_filter'][$tag] ?? null;

        if (!$hook instanceof \WP_Hook) {
            return 0;
        }

        $total = 0;
        foreach ($hook->callbacks as $callbacks) {
            $total += \count($callbacks);
        }

        return $total;
    }

    protected function markCreated(Identifier ...$tables): void
    {
        foreach ($tables as $table) {
            $this->createdTables[] = $table->value;
        }
    }

    protected static function dropTable(string $name): void
    {
        global $wpdb;

        $wpdb->query('DROP TABLE IF EXISTS '.$name);
    }

    /** @return list<string> */
    protected function indexNames(string $table): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SHOW INDEX FROM '.$table, ARRAY_A);

        $names = [];
        foreach ((array) $rows as $row) {
            $name = $row['Key_name'] ?? null;
            if (is_string($name) && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /** @return list<string> */
    protected function columnNames(string $table): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SHOW COLUMNS FROM '.$table, ARRAY_A);

        $names = [];
        foreach ((array) $rows as $row) {
            $name = $row['Field'] ?? null;
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    protected function tableExists(string $table): bool
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
            $table,
        )) === $table;
    }

    protected function tableEngine(string $table): ?string
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
            $table,
        ), ARRAY_A);

        $engine = is_array($row) ? ($row['ENGINE'] ?? null) : null;

        return is_string($engine) ? $engine : null;
    }

    /**
     * DDL implicitly commits, so the per-test transaction cannot undo a table
     * this suite created. Every table a fixture can leave behind is dropped
     * here, deterministically, before the next test starts.
     */
    /**
     * The tables this suite can leave behind, by canonical name. One list,
     * read by the per-test teardown and by the class-level drop of real
     * leftovers.
     *
     * @return list<string>
     */
    private static function declaredTables(): array
    {
        global $wpdb;

        $prefix = (string) $wpdb->prefix;

        return [
            MigrationLedgerSchema::table($prefix, self::identity(), (string) $wpdb->get_charset_collate())->name->value,
            NotesTable::nameFor($prefix)->value,
            Identifier::prefixed($prefix, 'fixture_meta')->value,
            $prefix.'fixture_myisam',
            $prefix.'fixture_orphans',
            $prefix.'sql_connection_probe',
        ];
    }

    private function dropCreatedTables(): void
    {
        foreach ([...self::declaredTables(), ...$this->createdTables] as $table) {
            self::dropTable($table);
        }

        $this->createdTables = [];
    }

    /**
     * Run a probe that is expected to make the database complain, without that
     * complaint failing the suite as unexpected output.
     *
     * @template T
     *
     * @param callable(): T $probe
     *
     * @return T
     */
    protected function silencingDatabaseErrors(callable $probe): mixed
    {
        global $wpdb;

        $previous = $wpdb->suppress_errors(true);

        try {
            return $probe();
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }
}

/**
 * A query source that counts nothing. Diagnostics only reads it for a span.
 *
 * @internal
 */
final class RecordingQuerySource implements QuerySource
{
    public function count(): int
    {
        return 0;
    }

    public function statements(): array
    {
        return [];
    }
}
