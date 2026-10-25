<?php

/**
 * Uninstall
 *
 * Deletes the data of Squidge from the database when
 * the plugin is deleted: its settings, the meta of the
 * attachments, its recent errors and background job.
 *
 * The files are kept: the compressed images, their WebP
 * and AVIF versions, and the originals in
 * uploads/squidge-originals/. A request may time out
 * before thousands of images are restored: use
 * "Restore original images" before deleting Squidge.
 *
 * @package     Squidge
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Uninstall
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit; // Exit if not uninstalled by WordPress.
}

global $wpdb;

// Settings (_squidge_*), recent errors and background job (squidge_*), cached theme scan.
$patterns = ['_squidge_%', 'squidge_%', '_transient_squidge_%', '_transient_timeout_squidge_%'];
foreach ($patterns as $pattern) {
	$like = str_replace('%', '', $pattern);
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($like) . '%'));
	if (is_multisite()) {
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like($like) . '%'));
	}
}

// Meta of the attachments: settings used, files made, errors, sizes.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_squidge_') . '%'));

wp_clear_scheduled_hook('squidge_job_cron');
wp_cache_flush();
