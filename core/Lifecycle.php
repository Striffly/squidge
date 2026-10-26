<?php

/**
 * Lifecycle
 *
 * What OIO does when it is activated and deactivated,
 * on a site or on every site of a network. Deleting
 * it is up to uninstall.php.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus;

use Octopus\Delivery\Htaccess;
use Octopus\Media\Job;
use Octopus\Media\Lock;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Lifecycle
{
	/**
	 * Writes the rewrite rules of the delivery again,
	 * if the option is on: deactivating OIO removed
	 * them.
	 *
	 * @param bool $network_wide Activated on every site of the network.
	 * @since 1.0.0
	 * @date 26/10/2026
	 */
	public static function activate($network_wide = false)
	{
		self::each_site($network_wide, [Htaccess::class, 'sync']);
	}

	/**
	 * Leaves nothing running behind OIO: the background
	 * job and its cron event stop, the locks of the
	 * images go, and the server stops serving the WebP
	 * and AVIF files in place of the images. The
	 * settings and the files stay, until OIO is
	 * deleted.
	 *
	 * @param bool $network_wide Deactivated on every site of the network.
	 * @since 1.0.0
	 * @date 26/10/2026
	 */
	public static function deactivate($network_wide = false)
	{
		self::each_site($network_wide, function () {
			global $wpdb;
			// The job of each site, see Job::__construct().
			if (class_exists('Octopus_Vendor_WP_Background_Process')) {
				(new Job())->stop();
			}
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(Lock::PREFIX) . '%'));
			Htaccess::remove_rules(Htaccess::htaccess());
		});
	}

	/**
	 * Runs a callback on the current site, or on every
	 * site of the network.
	 *
	 * @param bool $network_wide
	 * @param callable $callback
	 * @since 1.0.0
	 * @date 26/10/2026
	 */
	private static function each_site($network_wide, $callback)
	{
		if (!is_multisite() || !$network_wide) {
			$callback();
			return;
		}
		foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
			switch_to_blog($site_id);
			$callback();
			restore_current_blog();
		}
	}
}
