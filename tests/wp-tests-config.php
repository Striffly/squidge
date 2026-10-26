<?php

/**
 * The configuration of the WordPress test library. Its
 * database is emptied at each run: never the one of a
 * site. The environment variables below set it, the
 * defaults are those of the CI.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

// WordPress, installed by composer install.
define('ABSPATH', dirname(__DIR__) . '/vendor/roots/wordpress-no-content/');

define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'wordpress_test');
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'root');
define('DB_PASSWORD', (string)getenv('WP_TESTS_DB_PASSWORD'));
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'OIO tests');
define('WP_PHP_BINARY', PHP_BINARY);
define('WP_DEBUG', true);
