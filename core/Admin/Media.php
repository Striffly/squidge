<?php

/**
 * Media
 *
 * Media shows the OIO status of each image in
 * the media library (list mode), with an action to
 * optimise it.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Admin
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace OIO\Admin;

use OIO\Package\Cleaner;
use OIO\Package\Optimiser;
use OIO\Package\Stats;
use OIO\Package\Status;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Media
{
	/**
	 * Action optimising one image.
	 *
	 * @var string
	 */
	const ACTION = 'oio_optimise';

	/**
	 * Action restoring the original of one image.
	 *
	 * @var string
	 */
	const RESTORE = 'oio_restore';

	/**
	 * Query args of the notices after an action.
	 *
	 * @var string[]
	 */
	const NOTICES = ['oio_optimised', 'oio_restored', 'oio_busy'];

	/**
	 * Labels of the statuses, as shown to the user.
	 *
	 * @var array
	 */
	const LABELS = [
		Status::OPTIMISED => 'Optimised',
		Status::TO_OPTIMISE => 'To optimise',
		Status::OUTDATED => 'Older settings',
		Status::ERROR => 'Error',
	];

	/**
	 * Adds the column, the action and its notice.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function __construct()
	{
		add_filter('manage_media_columns', [$this, 'columns']);
		add_action('manage_media_custom_column', [$this, 'column'], 10, 2);
		add_filter('media_row_actions', [$this, 'row_actions'], 10, 2);
		add_action('admin_post_' . self::ACTION, [$this, 'optimise']);
		add_action('admin_post_' . self::RESTORE, [$this, 'restore']);
		add_action('admin_notices', [$this, 'notice']);
		add_action('admin_enqueue_scripts', [$this, 'styles']);
	}

	/**
	 * Determines if OIO optimises an attachment.
	 *
	 * @param int $id
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function is_image($id)
	{
		return in_array(get_post_mime_type($id), Status::MIMES, true);
	}

	/**
	 * Adds the OIO column.
	 *
	 * @param array $columns
	 * @return array
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function columns($columns)
	{
		$columns['oio'] = 'OIO';
		return $columns;
	}

	/**
	 * Renders the status of an image, and how much
	 * lighter the files the site serves are than its
	 * originals.
	 *
	 * @param string $name
	 * @param int $id
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function column($name, $id)
	{
		if ($name !== 'oio') {
			return;
		}
		if (!$this->is_image($id)) {
			echo '&mdash;';
			return;
		}
		$status = Status::of($id);
		printf('<span class="oio-status-label oio-status-label--%s">%s</span>', esc_attr($status), esc_html(self::LABELS[$status]));
		$bytes = Stats::of($id);
		$reduction = $bytes ? Stats::reduction($bytes, Stats::served()) : null;
		if ($reduction !== null) {
			printf(' <span class="oio-saved" title="%s">&middot; %s</span>', esc_attr($this->details($bytes)), esc_html($reduction));
		}
	}

	/**
	 * Returns the sizes of the files of an image, one
	 * per line, for the title of its reduction.
	 *
	 * @param array $bytes See Stats::KEYS.
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function details($bytes)
	{
		$lines = [
			'Original: ' . Stats::size($bytes['original']),
			'Compressed: ' . Stats::size($bytes['compressed']) . ' (' . Stats::reduction($bytes, 'compressed') . ')',
		];
		if ($bytes['extra']) {
			$lines[] = 'With WebP and AVIF: ' . Stats::size($bytes['modern']) . ' (' . Stats::reduction($bytes, 'modern') . ')';
			$lines[] = 'WebP and AVIF files: ' . Stats::size($bytes['extra']);
		}
		$lines[] = Stats::served() === 'modern' ? 'The site serves the WebP and AVIF.' : 'The site serves the JPG or PNG.';
		return implode("\n", $lines);
	}

	/**
	 * Adds the action optimising an image.
	 *
	 * @param array $actions
	 * @param \WP_Post $post
	 * @return array
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function row_actions($actions, $post)
	{
		if (!$this->is_image($post->ID) || !current_user_can('edit_post', $post->ID)) {
			return $actions;
		}
		$url = wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION . '&id=' . $post->ID), self::ACTION . '_' . $post->ID);
		$label = Status::of($post->ID) === Status::OPTIMISED ? 'Optimise again' : 'Optimise';
		$actions[self::ACTION] = sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($label));
		if ($this->is_processed($post->ID)) {
			$url = wp_nonce_url(admin_url('admin-post.php?action=' . self::RESTORE . '&id=' . $post->ID), self::RESTORE . '_' . $post->ID);
			$actions[self::RESTORE] = sprintf('<a href="%s">%s</a>', esc_url($url), 'Restore original');
		}
		return $actions;
	}

	/**
	 * Optimises an image with the current settings: the
	 * missing formats, the formats or thumbnails with
	 * older settings, or everything again from the
	 * original if it is already optimised.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function optimise()
	{
		$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
		check_admin_referer(self::ACTION . '_' . $id);
		if (!$id || !$this->is_image($id) || !current_user_can('edit_post', $id)) {
			wp_die('You cannot optimise this image.', 403);
		}
		$status = Status::of($id);
		if (in_array($status, [Status::OPTIMISED, Status::OUTDATED], true)) {
			$done = Optimiser::update($id, $status === Status::OPTIMISED);
		} else {
			$done = Optimiser::attachment($id, null, Optimiser::settings());
		}

		$redirect = remove_query_arg(self::NOTICES, wp_get_referer() ?: admin_url('upload.php?mode=list'));
		wp_safe_redirect(add_query_arg($done === null ? 'oio_busy' : 'oio_optimised', $id, $redirect));
		exit;
	}

	/**
	 * Determines if OIO processed an image.
	 *
	 * @param int $id
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private function is_processed($id)
	{
		foreach (array_keys(get_post_meta($id)) as $key) {
			if (strpos($key, '_oio_') === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Restores the original of an image and removes its
	 * OIO files.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function restore()
	{
		$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
		check_admin_referer(self::RESTORE . '_' . $id);
		if (!$id || !$this->is_image($id) || !current_user_can('edit_post', $id)) {
			wp_die('You cannot restore this image.', 403);
		}
		$restored = Cleaner::restore($id);

		$redirect = remove_query_arg(self::NOTICES, wp_get_referer() ?: admin_url('upload.php?mode=list'));
		if ($restored === null) {
			$redirect = add_query_arg('oio_busy', $id, $redirect);
		} else {
			$redirect = add_query_arg('oio_restored', $restored ? $id : -$id, $redirect);
		}
		wp_safe_redirect($redirect);
		exit;
	}

	/**
	 * Confirms the optimisation of an image. Its errors,
	 * if any, are shown by the errors notice.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function notice()
	{
		if (!current_user_can('upload_files')) {
			return;
		}
		if (!empty($_GET['oio_busy'])) {
			$id = (int)$_GET['oio_busy'];
			if (current_user_can('edit_post', $id)) {
				printf('<div class="notice notice-warning is-dismissible"><p><strong>OIO</strong> is already processing %s, try again in a moment.</p></div>', esc_html(get_the_title($id)));
			}
			return;
		}
		if (!empty($_GET['oio_restored'])) {
			$id = (int)$_GET['oio_restored'];
			if (!current_user_can('edit_post', abs($id))) {
				return;
			}
			if ($id > 0) {
				printf('<div class="notice notice-success is-dismissible"><p><strong>OIO</strong> restored the original of %s.</p></div>', esc_html(get_the_title($id)));
			} else {
				printf('<div class="notice notice-warning is-dismissible"><p><strong>OIO</strong> removed its files of %s, but its original is missing: it stays compressed.</p></div>', esc_html(get_the_title(-$id)));
			}
			return;
		}
		if (empty($_GET['oio_optimised'])) {
			return;
		}
		$id = (int)$_GET['oio_optimised'];
		if (!current_user_can('edit_post', $id) || Status::of($id) === Status::ERROR) {
			return;
		}
		printf('<div class="notice notice-success is-dismissible"><p><strong>OIO</strong> optimised %s.</p></div>', esc_html(get_the_title($id)));
	}

	/**
	 * Adds the colours of the statuses to the media
	 * library.
	 *
	 * @param string $hook
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function styles($hook)
	{
		if ($hook !== 'upload.php') {
			return;
		}
		wp_add_inline_style('wp-admin', '.column-oio{width:170px}.oio-saved{white-space:nowrap}.oio-status-label::before{content:"";display:inline-block;width:8px;height:8px;margin-right:6px;border-radius:50%;background:#a7aaad}.oio-status-label--optimised::before{background:#00a32a}.oio-status-label--outdated::before{background:#dba617}.oio-status-label--error::before{background:#d63638}');
	}
}
