<?php

/**
 * mahout-db test bootstrap.
 *
 * Resolves core's first-party test library from WP_TESTS_DIR and the test
 * configuration from WP_TESTS_CONFIG_FILE_PATH. A missing variable fails loudly
 * with its name: a silent skip is a green build with no tests.
 *
 * The fallbacks are the Phase 0 harness locations on this workstation and are
 * overridable; they are a publishing item, not a contract.
 */

declare(strict_types=1);

// Every statement is observable through wpdb's own buffer, which is how the
// run-path tests assert "nothing was written" without injecting a fake.
if (!defined('SAVEQUERIES')) {
    define('SAVEQUERIES', true);
}

$testsDir = getenv('WP_TESTS_DIR');
if (false === $testsDir || '' === $testsDir) {
    $testsDir = 'F:/kerjaan 2/WordPress/libraries/wordpress-develop/tests/phpunit';
}

if (!file_exists($testsDir.'/includes/bootstrap.php')) {
    fwrite(STDERR, sprintf('WP_TESTS_DIR does not contain includes/bootstrap.php: %s%s', $testsDir, PHP_EOL));
    exit(1);
}

$configFile = getenv('WP_TESTS_CONFIG_FILE_PATH');
if (false === $configFile || '' === $configFile) {
    $local = __DIR__.'/wp-tests-config.php';
    $configFile = is_file($local) ? $local : __DIR__.'/wp-tests-config.php.dist';
}

define('WP_TESTS_CONFIG_FILE_PATH', $configFile);
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__DIR__).'/vendor/yoast/phpunit-polyfills');

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once $testsDir.'/includes/functions.php';

tests_add_filter(
    'muplugins_loaded',
    static function (): void {
        // The provider is built per test; nothing needs a boot hook here.
    }
);

// Core's installer is a subprocess that prints its progress. The buffer is
// discarded so nothing is sent before PHPUnit starts, which would otherwise
// make every later header() call fail as "headers already sent" -- a failure
// that looks like a redirect bug and is really bootstrap chatter.
\ob_start();
require_once $testsDir.'/includes/bootstrap.php';
\ob_end_clean();

require_once __DIR__.'/TestCase.php';
