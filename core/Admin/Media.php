<?php

/**
 * Media
 *
 * Media shows the Squidge status of each image in
 * the media library (list mode), with an action to
 * optimise it.
 *
 * @package     Squidge
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Admin
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

namespace Squidge\Admin;

use Squidge\Package\Optimiser;
use Squidge\Package\Status;

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
	const ACTION = 'squidge_optimise';

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
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function __construct()
	{
		add_filter('manage_media_columns', [$this, 'columns']);
		add_action('manage_media_custom_column', [$this, 'column'], 10, 2);
		add_filter('media_row_actions', [$this, 'row_actions'], 10, 2);
		add_action('admin_post_' . self::ACTION, [$this, 'optimise']);
		add_action('admin_notices', [$this, 'notice']);
		add_action('admin_enqueue_scripts', [$this, 'styles']);
	}

	/**
	 * Determines if Squidge optimises an attachment.
	 *
	 * @param int $id
	 * @return bool
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	private function is_image($id)
	{
		return in_array(get_post_mime_type($id), Status::MIMES, true);
	}

	/**
	 * Adds the Squidge column.
	 *
	 * @param array $columns
	 * @return array
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function columns($columns)
	{
		$columns['squidge'] = 'Squidge';
		return $columns;
	}

	/**
	 * Renders the status of an image.
	 *
	 * @param string $name
	 * @param int $id
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function column($name, $id)
	{
		if ($name !== 'squidge') {
			return;
		}
		if (!$this->is_image($id)) {
			echo '&mdash;';
			return;
		}
		$status = Status::of($id);
		printf('<span class="squidge-status-label squidge-status-label--%s">%s</span>', esc_attr($status), esc_html(self::LABELS[$status]));
	}

	/**
	 * Adds the action optimising an image.
	 *
	 * @param array $actions
	 * @param \WP_Post $post
	 * @return array
	 * @since 0.1.5
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
		return $actions;
	}

	/**
	 * Optimises an image with the current settings: the
	 * missing formats, the formats or thumbnails with
	 * older settings, or everything again from the
	 * original if it is already optimised.
	 *
	 * @since 0.1.5
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
			Optimiser::update($id, $status === Status::OPTIMISED);
		} else {
			Optimiser::attachment($id, null, Optimiser::settings());
		}

		$redirect = wp_get_referer() ?: admin_url('upload.php?mode=list');
		wp_safe_redirect(add_query_arg('squidge_optimised', $id, remove_query_arg('squidge_optimised', $redirect)));
		exit;
	}

	/**
	 * Confirms the optimisation of an image. Its errors,
	 * if any, are shown by the errors notice.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function notice()
	{
		if (empty($_GET['squidge_optimised']) || !current_user_can('upload_files')) {
			return;
		}
		$id = (int)$_GET['squidge_optimised'];
		if (Status::of($id) === Status::ERROR) {
			return;
		}
		printf('<div class="notice notice-success is-dismissible"><p><strong>Squidge</strong> optimised %s.</p></div>', esc_html(get_the_title($id)));
	}

	/**
	 * Adds the colours of the statuses to the media
	 * library.
	 *
	 * @param string $hook
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function styles($hook)
	{
		if ($hook !== 'upload.php') {
			return;
		}
		wp_add_inline_style('wp-admin', '.column-squidge{width:120px}.squidge-status-label::before{content:"";display:inline-block;width:8px;height:8px;margin-right:6px;border-radius:50%;background:#a7aaad}.squidge-status-label--optimised::before{background:#00a32a}.squidge-status-label--outdated::before{background:#dba617}.squidge-status-label--error::before{background:#d63638}');
	}
}
