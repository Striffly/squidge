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
 * uploads/oio-originals/. A request may time out
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
 * The names of OIO only: a pattern as wide as oio_% would also delete the
 * options of another plugin using this prefix.
 */

// Settings, recent errors, progress of the background job, cached theme scan.
$options = ['oio_errors', 'oio_job', 'oio_theme_calls'];
$option_prefixes = ['_oio_'];
// The background job library keeps its queue, status and lock in the site options.
$site_options = ['oio_job_status', '_site_transient_oio_job_process_lock', '_site_transient_timeout_oio_job_process_lock'];
$site_option_prefixes = ['oio_job_batch_'];

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
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_oio_') . '%'));
	wp_clear_scheduled_hook('oio_job_cron');
};

if (is_multisite()) {
	foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
		switch_to_blog($site_id);
		$clean_site();
		restore_current_blog();
	}
	$table = $wpdb->sitemeta;
	$column = 'meta_key';
} else {
	$clean_site();
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
