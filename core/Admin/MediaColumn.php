<?php

/**
 * Media
 *
 * Media shows the OIO status of each image in
 * the media library (list mode), with actions to
 * optimise it, through the queue of the media library
 * (Queue), and to restore its original. With JS, the
 * actions run without leaving the page, and the cells
 * of the images waiting follow the queue
 * (MediaQueue.js); without, the links do the same and
 * come back with a notice.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Admin
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Admin;

use Octopus\Media\Cleaner;
use Octopus\Media\Files;
use Octopus\Media\Lock;
use Octopus\Media\Optimiser;
use Octopus\Media\Queue;
use Octopus\Media\Stats;
use Octopus\Media\Status;
use Octopus\Plugin;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class MediaColumn
{
	/**
	 * Action optimising one image.
	 *
	 * @var string
	 */
	const ACTION = 'octopus_optimise';

	/**
	 * Action restoring the original of one image.
	 *
	 * @var string
	 */
	const RESTORE = 'octopus_restore';

	/**
	 * Query args of the notices after an action.
	 *
	 * @var string[]
	 */
	const NOTICES = ['octopus_optimised', 'octopus_queued', 'octopus_restored', 'octopus_busy'];

	/**
	 * Nonce action of the requests of MediaQueue.js, and
	 * their AJAX actions: optimise an image, restore its
	 * original, and the state of the images waiting.
	 *
	 * @var string
	 */
	const NONCE = 'octopus_media';
	const AJAX_OPTIMISE = 'octopus_media_optimise';
	const AJAX_RESTORE = 'octopus_media_restore';
	const AJAX_STATE = 'octopus_media_state';

	/**
	 * The images in the queue, read once per request.
	 *
	 * @var int[]|null
	 */
	private $queued = null;

	/**
	 * Adds the column, the action and its notice.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		add_filter('manage_media_columns', [$this, 'columns']);
		add_action('manage_media_custom_column', [$this, 'column'], 10, 2);
		add_filter('media_row_actions', [$this, 'row_actions'], 10, 2);
		add_action('admin_post_' . self::ACTION, [$this, 'optimise']);
		add_action('admin_post_' . self::RESTORE, [$this, 'restore']);
		add_action('wp_ajax_' . self::AJAX_OPTIMISE, [$this, 'ajax_optimise']);
		add_action('wp_ajax_' . self::AJAX_RESTORE, [$this, 'ajax_restore']);
		add_action('wp_ajax_' . self::AJAX_STATE, [$this, 'ajax_state']);
		add_action('admin_notices', [$this, 'notice']);
		add_action('admin_enqueue_scripts', [$this, 'assets']);
		add_filter('admin_body_class', [$this, 'body_class']);
	}

	/**
	 * Returns the labels of the statuses, as shown to
	 * the user.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public static function labels()
	{
		return [
			Status::OPTIMISED => __('Optimised', 'octopus-image-optimizer'),
			Status::TO_OPTIMISE => __('To optimise', 'octopus-image-optimizer'),
			Status::OUTDATED => __('Older settings', 'octopus-image-optimizer'),
			Status::ERROR => __('Error', 'octopus-image-optimizer'),
		];
	}

	/**
	 * Determines if OIO optimises an attachment.
	 *
	 * @param int $id
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_image($id)
	{
		return in_array(get_post_mime_type($id), Files::MIMES, true);
	}

	/**
	 * Determines if the user may optimise or restore an
	 * image: OIO optimises it, and the user may edit it.
	 *
	 * @param int $id
	 * @return bool
	 * @since 1.0.0
	 */
	private function editable($id)
	{
		return $id && $this->is_image($id) && current_user_can('edit_post', $id);
	}

	/**
	 * Adds the Optimisation column.
	 *
	 * @param array $columns
	 * @return array
	 * @since 1.0.0
	 */
	public function columns($columns)
	{
		$columns['octopus'] = __('Optimisation', 'octopus-image-optimizer');
		return $columns;
	}

	/**
	 * Renders the cell of an image (see cell()).
	 *
	 * @param string $name
	 * @param int $id
	 * @since 1.0.0
	 */
	public function column($name, $id)
	{
		if ($name !== 'octopus') {
			return;
		}
		if (!$this->is_image($id)) {
			echo '&mdash;';
			return;
		}
		echo $this->cell($id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell().
	}

	/**
	 * Returns the cell of an image: waiting in the
	 * queue, being optimised, or its status and how much
	 * lighter the files the site serves are than its
	 * originals, with a message after an action, if
	 * any.
	 *
	 * @param int $id
	 * @param string $message
	 * @return string
	 * @since 1.0.0
	 */
	private function cell($id, $message = '')
	{
		$pending = $this->pending($id);
		$html = sprintf('<span class="octopus-media-cell js-octopus-media-cell" data-id="%d" data-pending="%d">', $id, $pending ? 1 : 0);
		if ($pending) {
			$label = $pending === 'processing' ? __('Optimising…', 'octopus-image-optimizer') : __('Waiting', 'octopus-image-optimizer');
			return $html . sprintf('<span class="octopus-status-label octopus-status-label--%s">%s</span></span>', esc_attr($pending), esc_html($label));
		}
		$status = Status::of($id);
		$html .= sprintf('<span class="octopus-status-label octopus-status-label--%s">%s</span>', esc_attr($status), esc_html(self::labels()[$status]));
		$bytes = Stats::of($id);
		$reduction = $bytes ? Stats::reduction($bytes, Stats::served()) : null;
		if ($reduction !== null) {
			$html .= sprintf(' <span class="octopus-saved" title="%s">&middot; %s</span>', esc_attr($this->details($bytes)), esc_html($reduction));
		}
		if ($message !== '') {
			$html .= sprintf('<span class="octopus-media-cell__message">%s</span>', esc_html($message));
		}
		return $html . '</span>';
	}

	/**
	 * Returns where an image is in the queue: waiting,
	 * processing, or empty if it is not in it.
	 *
	 * @param int $id
	 * @return string
	 * @since 1.0.0
	 */
	private function pending($id)
	{
		$queue = self::queue();
		if (!$queue) {
			return '';
		}
		if ($this->queued === null) {
			$this->queued = $queue->ids();
		}
		if (!in_array((int)$id, $this->queued, true)) {
			return '';
		}
		return Lock::locked($id) ? 'processing' : 'waiting';
	}

	/**
	 * Returns the queue of the media library, null
	 * without the dependencies of composer install.
	 *
	 * @return Queue|null
	 * @since 1.0.0
	 */
	private static function queue()
	{
		return Plugin::has_background() ? Queue::instance() : null;
	}

	/**
	 * Returns the sizes of the files of an image, one
	 * per line, for the title of its reduction.
	 *
	 * @param array $bytes See Stats::KEYS.
	 * @return string
	 * @since 1.0.0
	 */
	private function details($bytes)
	{
		$lines = [
			/* translators: %s: a size, e.g. 1.2 MB. */
			sprintf(__('Original: %s', 'octopus-image-optimizer'), Stats::size($bytes['original'])),
			/* translators: 1: a size, e.g. 640 KB, 2: its reduction, e.g. −48 %. */
			sprintf(__('Compressed: %1$s (%2$s)', 'octopus-image-optimizer'), Stats::size($bytes['compressed']), Stats::reduction($bytes, 'compressed')),
		];
		if ($bytes['extra']) {
			/* translators: 1: a size, e.g. 420 KB, 2: its reduction, e.g. −65 %. */
			$lines[] = sprintf(__('With WebP and AVIF: %1$s (%2$s)', 'octopus-image-optimizer'), Stats::size($bytes['modern']), Stats::reduction($bytes, 'modern'));
			/* translators: %s: a size, e.g. 900 KB. */
			$lines[] = sprintf(__('WebP and AVIF files: %s', 'octopus-image-optimizer'), Stats::size($bytes['extra']));
		}
		$lines[] = Stats::served() === 'modern' ? __('The site serves the WebP and AVIF.', 'octopus-image-optimizer') : __('The site serves the JPG or PNG.', 'octopus-image-optimizer');
		return implode("\n", $lines);
	}

	/**
	 * Adds the action optimising an image.
	 *
	 * @param array $actions
	 * @param \WP_Post $post
	 * @return array
	 * @since 1.0.0
	 */
	public function row_actions($actions, $post)
	{
		if (!$this->editable($post->ID)) {
			return $actions;
		}
		$actions['octopus'] = $this->actions($post->ID);
		return $actions;
	}

	/**
	 * Returns the actions of an image, in one place of
	 * its row: links, which MediaQueue.js runs without
	 * leaving the page, and renders again as a whole.
	 * Each link carries the labels it takes meanwhile:
	 * while it runs, from the click or while the image
	 * waits in the queue, then once it is done.
	 *
	 * @param int $id
	 * @return string
	 * @since 1.0.0
	 */
	private function actions($id)
	{
		$pending = $this->pending($id) !== '';
		$links = [$this->link($id, self::ACTION, self::AJAX_OPTIMISE, [
			Status::of($id) === Status::OPTIMISED ? __('Optimise again', 'octopus-image-optimizer') : __('Optimise', 'octopus-image-optimizer'),
			__('Optimising…', 'octopus-image-optimizer'),
			__('Optimised!', 'octopus-image-optimizer'),
		], $pending)];
		if (Cleaner::has_data($id)) {
			$links[] = $this->link($id, self::RESTORE, self::AJAX_RESTORE, [
				__('Restore original', 'octopus-image-optimizer'),
				__('Restoring…', 'octopus-image-optimizer'),
				__('Restored!', 'octopus-image-optimizer'),
			]);
		}
		// Separated as WordPress separates the actions. Busy while the image waits in the queue.
		return sprintf(
			'<span class="octopus-media-actions js-octopus-media-actions" data-id="%d"%s>%s</span>',
			$id,
			$pending ? sprintf(' data-busy="%s"', esc_attr(self::AJAX_OPTIMISE)) : '',
			implode(' | ', $links)
		);
	}

	/**
	 * Returns the link of an action of an image, with
	 * its labels: as it is, while it runs, once done.
	 *
	 * @param int $id
	 * @param string $action Its admin-post action, without JS.
	 * @param string $request Its AJAX action.
	 * @param string[] $labels
	 * @param bool $running Whether it runs: its label then.
	 * @return string
	 * @since 1.0.0
	 */
	private function link($id, $action, $request, $labels, $running = false)
	{
		list($label, $busy, $done) = $labels;
		return sprintf(
			'<a href="%s" class="js-octopus-media-action" data-request="%s" data-id="%d" data-label-busy="%s" data-label-done="%s">%s</a>',
			esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . $action . '&id=' . $id), $action . '_' . $id)),
			esc_attr($request),
			$id,
			esc_attr($busy),
			esc_attr($done),
			esc_html($running ? $busy : $label)
		);
	}

	/**
	 * Returns the state of an image, as MediaQueue.js
	 * renders it: its cell, its actions, whether it
	 * waits in the queue, and whether the action failed
	 * (its message in the cell).
	 *
	 * @param int $id
	 * @param string $message Shown in its cell.
	 * @return array
	 * @since 1.0.0
	 */
	private function state($id, $message = '')
	{
		return ['id' => $id, 'cell' => $this->cell($id, $message), 'actions' => $this->actions($id), 'pending' => $this->pending($id) !== '', 'failed' => $message !== ''];
	}

	/**
	 * Returns the image of a request of MediaQueue.js,
	 * once its nonce checked and the user allowed to
	 * edit it; ends the request otherwise.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	private function requested()
	{
		check_ajax_referer(self::NONCE);
		$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
		if (!$this->editable($id)) {
			wp_send_json_error(null, 403);
		}
		return $id;
	}

	/**
	 * Adds an image to the queue of the media library,
	 * from MediaQueue.js: one library runs at a time,
	 * however many images are asked. Without the
	 * queue, optimises it at once.
	 *
	 * @since 1.0.0
	 */
	public function ajax_optimise()
	{
		$id = $this->requested();
		$this->optimise_image($id);
		wp_send_json_success($this->state($id));
	}

	/**
	 * Adds an image to the queue of the media library,
	 * or optimises it at once without the queue.
	 *
	 * @param int $id
	 * @return string queued, optimised, or busy if another request is processing the image.
	 * @since 1.0.0
	 */
	private function optimise_image($id)
	{
		$queue = self::queue();
		if ($queue) {
			$queue->add($id);
			$this->queued = null;
			return 'queued';
		}
		return Optimiser::optimise($id) === null ? 'busy' : 'optimised';
	}

	/**
	 * Restores the original of an image, from
	 * MediaQueue.js, with a message in its cell when it
	 * could not.
	 *
	 * @since 1.0.0
	 */
	public function ajax_restore()
	{
		$id = $this->requested();
		$restored = Cleaner::restore($id);
		$message = '';
		if ($restored === null) {
			$message = __('Being processed, try again in a moment.', 'octopus-image-optimizer');
		} elseif ($restored === false) {
			$message = __('Original missing: the image stays compressed.', 'octopus-image-optimizer');
		}
		wp_send_json_success($this->state($id, $message));
	}

	/**
	 * Returns the state of images waiting in the queue,
	 * asked by MediaQueue.js until they are done: those
	 * the user may edit, 100 at most.
	 *
	 * @since 1.0.0
	 */
	public function ajax_state()
	{
		check_ajax_referer(self::NONCE);
		// The images its worker left go on while the media library follows them, see Background::settle().
		if (self::queue() && current_user_can('upload_files')) {
			self::queue()->settle();
		}
		$ids = isset($_POST['ids']) ? array_slice(array_map('intval', explode(',', sanitize_text_field(wp_unslash($_POST['ids'])))), 0, 100) : [];
		$states = [];
		foreach (array_unique($ids) as $id) {
			if ($this->editable($id)) {
				$states[] = $this->state($id);
			}
		}
		wp_send_json_success($states);
	}

	/**
	 * Optimises an image with the current settings,
	 * without JS: the missing formats, the formats or
	 * thumbnails with older settings, or everything
	 * again from the original if it is already
	 * optimised. Through the queue of the media library,
	 * as MediaQueue.js does.
	 *
	 * @since 1.0.0
	 */
	public function optimise()
	{
		$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
		check_admin_referer(self::ACTION . '_' . $id);
		if (!$this->editable($id)) {
			wp_die(esc_html__('You cannot optimise this image.', 'octopus-image-optimizer'), 403);
		}
		$this->back('octopus_' . $this->optimise_image($id), $id);
	}

	/**
	 * Restores the original of an image and removes its
	 * OIO files.
	 *
	 * @since 1.0.0
	 */
	public function restore()
	{
		$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
		check_admin_referer(self::RESTORE . '_' . $id);
		if (!$this->editable($id)) {
			wp_die(esc_html__('You cannot restore this image.', 'octopus-image-optimizer'), 403);
		}
		$restored = Cleaner::restore($id);
		if ($restored === null) {
			$this->back('octopus_busy', $id);
		}
		$this->back('octopus_restored', $restored ? $id : -$id);
	}

	/**
	 * Goes back to the media library after an action
	 * without JS, with the notice telling how it went.
	 *
	 * @param string $notice One of NOTICES.
	 * @param int $id The image, negative for a failure.
	 * @since 1.0.0
	 */
	private function back($notice, $id)
	{
		$redirect = remove_query_arg(self::NOTICES, wp_get_referer() ?: admin_url('upload.php?mode=list'));
		wp_safe_redirect(add_query_arg($notice, $id, $redirect));
		exit;
	}

	/**
	 * Confirms the optimisation of an image. Its errors,
	 * if any, are shown by the errors notice.
	 *
	 * @since 1.0.0
	 */
	public function notice()
	{
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Query args of the redirect after an action checked by its nonce: they only pick a notice.
		if (!current_user_can('upload_files')) {
			return;
		}
		if (!empty($_GET['octopus_busy'])) {
			$id = (int)$_GET['octopus_busy'];
			if (current_user_can('edit_post', $id)) {
				/* translators: %s: the title of an image. */
				$this->notice_html('warning', __('Octopus Image Optimizer is already processing %s, try again in a moment.', 'octopus-image-optimizer'), get_the_title($id));
			}
			return;
		}
		if (!empty($_GET['octopus_queued'])) {
			$id = (int)$_GET['octopus_queued'];
			if (current_user_can('edit_post', $id)) {
				/* translators: %s: the title of an image. */
				$this->notice_html('success', __('Octopus Image Optimizer will optimise %s in a moment.', 'octopus-image-optimizer'), get_the_title($id));
			}
			return;
		}
		if (!empty($_GET['octopus_restored'])) {
			$id = (int)$_GET['octopus_restored'];
			if (!current_user_can('edit_post', abs($id))) {
				return;
			}
			if ($id > 0) {
				/* translators: %s: the title of an image. */
				$this->notice_html('success', __('Octopus Image Optimizer restored the original of %s.', 'octopus-image-optimizer'), get_the_title($id));
			} else {
				/* translators: %s: the title of an image. */
				$this->notice_html('warning', __('Octopus Image Optimizer removed its files of %s, but its original is missing: it stays compressed.', 'octopus-image-optimizer'), get_the_title(-$id));
			}
			return;
		}
		if (empty($_GET['octopus_optimised'])) {
			return;
		}
		$id = (int)$_GET['octopus_optimised'];
		if (!current_user_can('edit_post', $id) || Status::of($id) === Status::ERROR) {
			return;
		}
		/* translators: %s: the title of an image. */
		$this->notice_html('success', __('Octopus Image Optimizer optimised %s.', 'octopus-image-optimizer'), get_the_title($id));
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Renders a notice about an image, its title in
	 * place of %s. The name of OIO is in bold.
	 *
	 * @param string $type success or warning.
	 * @param string $text
	 * @param string $title
	 * @since 1.0.0
	 */
	private function notice_html($type, $text, $title)
	{
		$text = str_replace('Octopus Image Optimizer', '<strong>Octopus Image Optimizer</strong>', esc_html($text));
		printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), sprintf($text, esc_html($title))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Adds the colours of the statuses to the media
	 * library, and the script running its actions.
	 *
	 * @param string $hook
	 * @since 1.0.0
	 */
	public function assets($hook)
	{
		if ($hook !== 'upload.php') {
			return;
		}
		Plugin::enqueue_style('screens/media');
		$script = Plugin::enqueue_chain(['utils/Api', 'views/Screen', 'components/Component', 'components/MediaQueue', 'media'], 'script');
		wp_localize_script($script, 'octopusMedia', [
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce(self::NONCE),
		]);
	}

	/**
	 * Marks the media library for MediaQueue.js.
	 *
	 * @param string $classes
	 * @return string
	 * @since 1.0.0
	 */
	public function body_class($classes)
	{
		$screen = get_current_screen();
		return $screen && $screen->id === 'upload' ? $classes . ' js-octopus-media' : $classes;
	}
}
