<?php

/**
 * UninstallTest
 *
 * Deleting OIO deletes its data, and nothing else,
 * on a single site and on every site of a network.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace OIO\Tests\Integration;

use OIO\Admin\Delivery;
use OIO\Admin\Fields;
use OIO\Tests\TestCase;
use ReflectionMethod;

class UninstallTest extends TestCase
{
	/**
	 * The options of other plugins, kept by the
	 * uninstall.
	 */
	const FOREIGN = ['oio_other_plugin', '_oio_other_plugin', 'oio_jobs'];

	/**
	 * The rules of another plugin in uploads/.htaccess.
	 */
	const OTHER_RULES = "# Another plugin\nOptions -Indexes\n";

	public function test_the_data_of_oio_is_deleted_from_a_site()
	{
		$this->skipWithMultisite();
		$attachment = $this->fill_site(true);
		$this->fill_job('oio_job');

		self::uninstall();

		$this->assert_site_clean($attachment);
		$this->assert_job_clean('oio_job');
		$this->assertSame(self::OTHER_RULES, file_get_contents(Delivery::htaccess()));
	}

	public function test_the_data_of_oio_is_deleted_from_every_site_of_a_network()
	{
		$this->skipWithoutMultisite();
		$main = get_current_blog_id();
		$sites = [$main => $this->fill_site(true)];
		$this->fill_job('oio_' . $main . '_job');
		$other = self::factory()->blog->create();
		switch_to_blog($other);
		$sites[$other] = $this->fill_site(false);
		$this->fill_job('oio_' . $other . '_job');
		restore_current_blog();

		self::uninstall();

		foreach ($sites as $site => $attachment) {
			switch_to_blog($site);
			$this->assert_site_clean($attachment);
			$this->assert_job_clean('oio_' . $site . '_job');
			restore_current_blog();
		}
		$this->assertSame(self::OTHER_RULES, file_get_contents($this->uploads . '/.htaccess'));
		$this->assertFileDoesNotExist($this->uploads . '/sites/' . $other . '/.htaccess', 'It held the rules of OIO only.');
	}

	/**
	 * Saves the data of OIO on the current site, and of
	 * other plugins.
	 *
	 * @param bool $other_rules Whether the .htaccess has the rules of another plugin.
	 * @return int An attachment with metas of OIO.
	 */
	private function fill_site($other_rules)
	{
		// Every field of the options page: uninstall.php lists them by hand.
		$sections = new ReflectionMethod(Fields::class, 'sections');
		$sections->setAccessible(true);
		foreach ($sections->invoke(null) as $section) {
			foreach ($section['fields'] as $name => $field) {
				update_option(Fields::OPTION_PREFIX . $name, 'yes');
			}
		}
		foreach (['oio_errors', 'oio_job', 'oio_theme_calls', 'oio_lock_7'] as $option) {
			update_option($option, ['data']);
		}
		set_transient('oio_versions', ['cwebp' => '1.6.0']);
		foreach (self::FOREIGN as $option) {
			update_option($option, 'kept');
		}
		$id = self::factory()->attachment->create_object(['file' => '2026/10/photo.jpg', 'post_mime_type' => 'image/jpeg']);
		update_post_meta($id, '_oio_files', ['2026/10/photo.jpg.webp']);
		update_post_meta($id, '_oio_compressed_cwebp', 'quality:80');
		update_post_meta($id, 'oio_other_plugin', 'kept');

		wp_mkdir_p(dirname(Delivery::htaccess()));
		if ($other_rules) {
			file_put_contents(Delivery::htaccess(), self::OTHER_RULES);
		}
		update_option(Fields::OPTION_PREFIX . Delivery::OPTION, 'no');
		update_option(Fields::OPTION_PREFIX . Delivery::OPTION, Fields::CHECKBOX_ON);
		$this->assertTrue(Delivery::rules_written());
		return $id;
	}

	/**
	 * Saves the queue, status and lock of a background
	 * job, as the library does: in the options of the
	 * network, its cron event in the current site.
	 *
	 * @param string $identifier
	 */
	private function fill_job($identifier)
	{
		update_site_option($identifier . '_batch_' . md5($identifier), [1, 2, 3]);
		update_site_option($identifier . '_status', 1);
		set_site_transient($identifier . '_process_lock', microtime(), 60);
		wp_schedule_event(time(), 'hourly', $identifier . '_cron');
	}

	/**
	 * Runs uninstall.php, as WordPress does when OIO is
	 * deleted.
	 */
	private static function uninstall()
	{
		if (!defined('WP_UNINSTALL_PLUGIN')) {
			define('WP_UNINSTALL_PLUGIN', 'octopus-image-optimizer/octopus-image-optimizer.php');
		}
		include dirname(__DIR__, 2) . '/uninstall.php';
	}

	/**
	 * Checks that the current site has no data of OIO
	 * left, and that the data of other plugins stays.
	 *
	 * @param int $attachment
	 */
	private function assert_site_clean($attachment)
	{
		global $wpdb;
		$left = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%oio%' ORDER BY option_name");
		$foreign = self::FOREIGN;
		sort($foreign);
		$this->assertSame($foreign, $left);
		$this->assertSame(['oio_other_plugin'], array_values(preg_grep('/oio/', array_keys(get_post_meta($attachment)))));
		$this->assertFalse(Delivery::rules_written());
	}

	/**
	 * Checks that a background job has nothing left.
	 *
	 * @param string $identifier
	 */
	private function assert_job_clean($identifier)
	{
		global $wpdb;
		list($table, $column) = is_multisite() ? [$wpdb->sitemeta, 'meta_key'] : [$wpdb->options, 'option_name'];
		$left = $wpdb->get_col($wpdb->prepare("SELECT {$column} FROM {$table} WHERE {$column} LIKE %s", '%' . $wpdb->esc_like($identifier . '_') . '%'));
		$this->assertSame([], $left);
		$this->assertFalse(wp_next_scheduled($identifier . '_cron'));
	}
}
