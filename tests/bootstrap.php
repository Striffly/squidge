<?php

/**
 * Bootstrap of the integration tests: WordPress and its
 * test library, with OIO loaded as a must-use plugin.
 * See the Tests section of the README.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

$root = dirname(__DIR__);
$missing = [
	$root . '/vendor/autoload.php' => 'run composer install',
	$root . '/vendor-prefixed/autoload.php' => 'run composer install',
];
foreach ($missing as $file => $fix) {
	if (!file_exists($file)) {
		fwrite(STDERR, "Missing {$file}: {$fix}." . PHP_EOL);
		exit(1);
	}
}

// Sets WP_PHPUNIT__DIR, the folder of the test library.
require $root . '/vendor/autoload.php';
putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $root . '/vendor/yoast/phpunit-polyfills');

// The messages of OIO (Logger) go to a file, not between the results.
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'octopus-tests.log');

$library = getenv('WP_PHPUNIT__DIR');
require $library . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function () use ($root) {
	require $root . '/octopus-image-optimizer.php';
});

require $library . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
