<?php

/**
 * Uninstall
 *
 * Deletes the data of OIO from the database when
 * the plugin is deleted: its settings, the meta of the
 * attachments, its recent errors and background job.
 *
 * The files are kept: the compressed images, their WebP
 * and AVIF versions, and the originals in
 * uploads/octopus-originals/. A request may time out
 * before thousands of images are restored: use
 * "Restore original images" before deleting OIO.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Uninstall
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit; // Exit if not uninstalled by WordPress.
}

global $wpdb;

/*
 * The names of OIO only: a pattern as wide as octopus_% would also delete the
 * options of another plugin using this prefix.
 */

// Recent errors, progress of the background job, cached theme scan and versions of the
// libraries, the settings, locks of the images.
$options = ['octopus_errors', 'octopus_job', 'octopus_theme_calls', 'octopus_versions', 'octopus_settings'];
$option_prefixes = ['octopus_lock_'];
// The background job library keeps its queue, status and lock in the site options,
// under the identifier of the job: octopus_job, or octopus_2_job on the site 2 of a network.
$site_options = [];
$site_option_prefixes = [];
$add_job = function ($identifier) use (&$site_options, &$site_option_prefixes) {
	array_push($site_options, $identifier . '_status', '_site_transient_' . $identifier . '_process_lock', '_site_transient_timeout_' . $identifier . '_process_lock');
	$site_option_prefixes[] = $identifier . '_batch_';
	wp_clear_scheduled_hook($identifier . '_cron');
};

/**
 * Deletes the options of OIO and the meta of the
 * attachments of the current site.
 */
$clean_site = function () use ($wpdb, $options, $option_prefixes) {
	foreach ($options as $option) {
		delete_option($option);
		delete_transient($option);
	}
	foreach ($option_prefixes as $prefix) {
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%'));
	}
	// Meta of the attachments: settings used, files made, errors, sizes.
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_octopus_') . '%'));
	// The rewrite rules of the delivery, in uploads/.htaccess, go with OIO.
	$htaccess = wp_get_upload_dir()['basedir'] . DIRECTORY_SEPARATOR . '.htaccess';
	if (is_file($htaccess) && is_writable($htaccess)) {
		// As Htaccess::remove_rules(): the block and its markers, the file if it held only them.
		$cleaned = preg_replace('/\n*# BEGIN Octopus Image Optimizer\n.*?# END Octopus Image Optimizer\n?/s', "\n", (string)file_get_contents($htaccess), -1, $count);
		if ($count && trim($cleaned) === '') {
			unlink($htaccess);
		} elseif ($count) {
			file_put_contents($htaccess, ltrim($cleaned, "\n"));
		}
	}
};

if (is_multisite()) {
	foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
		switch_to_blog($site_id);
		$clean_site();
		$add_job('octopus_' . $site_id . '_job');
		restore_current_blog();
	}
	$table = $wpdb->sitemeta;
	$column = 'meta_key';
} else {
	$clean_site();
	$add_job('octopus_job');
	$table = $wpdb->options;
	$column = 'option_name';
}

foreach ($site_options as $option) {
	$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE {$column} = %s", $option));
}
foreach ($site_option_prefixes as $prefix) {
	$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE {$column} LIKE %s", $wpdb->esc_like($prefix) . '%'));
}

wp_cache_flush();
