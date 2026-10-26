<?php

/**
 * Autoload
 *
 * Loads the classes of OIO (Octopus\Media\Files =>
 * core/Media/Files.php) and its dependencies,
 * prefixed by Strauss (composer install). Shared by
 * the plugin and uninstall.php.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

spl_autoload_register(function ($class) {
	$prefix = 'Octopus\\';
	if (strpos($class, $prefix) !== 0) {
		return;
	}
	$file = __DIR__ . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (file_exists($file)) {
		require $file;
	}
});

if (file_exists(__DIR__ . '/vendor-prefixed/autoload.php')) {
	require_once __DIR__ . '/vendor-prefixed/autoload.php';
}
