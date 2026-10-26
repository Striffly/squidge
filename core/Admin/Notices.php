<?php

/**
 * Notices
 *
 * Notices shows the library failures to the user:
 * right after an upload in the media library, and
 * as an admin notice on the media screens until
 * it is dismissed.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Admin
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Admin;

use Octopus\Log\Errors;
use Octopus\Media\Optimiser;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Notices
{
	/**
	 * Nonce action of the dismiss request.
	 *
	 * @var string
	 */
	const NONCE = 'octopus_dismiss_errors';

	/**
	 * Screens where the notice is shown: media library,
	 * add media, edit media and the options page.
	 *
	 * @var array
	 */
	const SCREENS = ['upload', 'media', 'attachment', 'settings_page_' . SettingsPage::PAGE];

	/**
	 * What failed, by format, as shown to the user.
	 * WordPress reads the images first, see
	 * Optimiser::readable().
	 *
	 * @var array
	 */
	const ACTIONS = [
		Optimiser::READER => 'Optimisation',
		'jpg' => 'JPG compression',
		'png' => 'PNG compression',
		'webp' => 'WebP conversion',
		'avif' => 'AVIF conversion',
	];

	/**
	 * The tags of an item of the notice (see
	 * items_html()), escaped once more on output.
	 *
	 * @var array
	 */
	const ITEM_TAGS = [
		'a' => ['href' => true],
		'strong' => [],
		'details' => ['class' => true],
		'summary' => [],
		'code' => [],
		'br' => [],
	];

	/**
	 * Likely causes of the library errors, as shown to
	 * the user, matched against the technical message
	 * in this order.
	 *
	 * @var array
	 */
	const REASONS = [
		'/unsupported color conversion/i' => 'the image uses CMYK colours (a print format) that the library cannot read.',
		'/no space left/i' => 'the server disk is full.',
		'/not enough PHP memory/i' => 'the image is too big for the PHP memory: install the library, or lower the maximum image size.',
		'/permission denied|read-only|cannot (open|create|write)/i' => 'the file could not be written, check the permissions of the uploads folder.',
		'/cannot read the image/i' => 'the image file is damaged or incomplete, WordPress cannot read it.',
		'/premature end|corrupt|not a (jpeg|png)|recoverable errors|bad huffman|invalid|input file read error|cannot read input file/i' => 'the image file seems damaged or incomplete.',
		'/not found|code 127/i' => 'the library does not work on the server, check its installation.',
	];

	/**
	 * Adds the notice, its script and the errors to
	 * the attachment data sent after an upload.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		add_action('admin_notices', [$this, 'render']);
		add_action('admin_enqueue_scripts', [$this, 'scripts']);
		add_action('wp_ajax_' . self::NONCE, [$this, 'dismiss']);
		add_filter('wp_prepare_attachment_for_js', [$this, 'attachment_for_js'], 10, 2);
	}

	/**
	 * Determines if the current screen shows the notice.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_notice_screen()
	{
		$screen = get_current_screen();
		return $screen && in_array($screen->id, self::SCREENS, true) && current_user_can('upload_files');
	}

	/**
	 * Renders the recent errors as an admin notice, to
	 * the administrators: they list the images of
	 * every user. The others see the errors of their
	 * uploads right after uploading them.
	 *
	 * @since 1.0.0
	 */
	public function render()
	{
		if (!$this->is_notice_screen() || !current_user_can('manage_options')) {
			return;
		}
		$errors = Errors::recent();
		if (empty($errors)) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible octopus-errors-notice">
			<p><strong>Octopus Image Optimizer</strong> could not fully optimise some images:</p>
			<ul>
				<?php foreach ($this->items_html($errors) as $html) : ?>
					<li><?php echo wp_kses($html, self::ITEM_TAGS); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Groups errors by attachment and cause, and returns
	 * the HTML of each group: the image, what failed and
	 * why, then the technical messages in a toggle.
	 *
	 * @param array $errors
	 * @return array
	 * @since 1.0.0
	 */
	private function items_html($errors)
	{
		$groups = [];
		foreach ($errors as $error) {
			$reason = $this->reason($error['message']);
			$key = $error['id'] . '|' . ($error['id'] ? '' : $error['file']) . '|' . $reason;
			if (!isset($groups[$key])) {
				$groups[$key] = ['id' => $error['id'], 'file' => $error['file'], 'reason' => $reason, 'actions' => [], 'details' => []];
			}
			$action = array_key_exists($error['format'], self::ACTIONS) ? self::ACTIONS[$error['format']] : $error['format'];
			$groups[$key]['actions'][$action] = $action;
			$groups[$key]['details'][] = sprintf('%s: %s', $error['file'], $error['message']);
		}

		$items = [];
		foreach ($groups as $group) {
			$items[] = sprintf(
				'%s &mdash; %s failed: %s <details class="octopus-error-details"><summary>Technical details</summary><code>%s</code></details>',
				$this->image_html($group['id'], $group['file']),
				esc_html(implode(' and ', $group['actions'])),
				esc_html($group['reason']),
				implode('<br>', array_map('esc_html', array_unique($group['details'])))
			);
		}
		return $items;
	}

	/**
	 * Returns the likely cause of a library error.
	 *
	 * @param string $message
	 * @return string
	 * @since 1.0.0
	 */
	private function reason($message)
	{
		foreach (self::REASONS as $pattern => $reason) {
			if (preg_match($pattern, $message)) {
				return $reason;
			}
		}
		return 'an unexpected error occurred, see the technical details.';
	}

	/**
	 * Returns the image name, linked to its attachment
	 * if it still exists.
	 *
	 * @param int $id
	 * @param string $file
	 * @return string
	 * @since 1.0.0
	 */
	private function image_html($id, $file)
	{
		$path = $id ? get_attached_file($id) : '';
		$name = $path ? basename($path) : $file;
		$link = $id ? get_edit_post_link($id) : '';
		if (!$link) {
			return sprintf('<strong>%s</strong>', esc_html($name));
		}
		return sprintf('<a href="%s"><strong>%s</strong></a>', esc_url($link), esc_html($name));
	}

	/**
	 * Enqueues the script that shows the errors right
	 * after an upload and clears them on dismiss.
	 *
	 * @since 1.0.0
	 */
	public function scripts()
	{
		if (!$this->is_notice_screen()) {
			return;
		}
		wp_enqueue_script('octopus-notices', OCTOPUS_URL . 'assets/notices.js', ['jquery'], filemtime(OCTOPUS_PATH . 'assets/notices.js'), true);
		wp_localize_script('octopus-notices', 'octopusNotices', [
			'action' => self::NONCE,
			'nonce' => wp_create_nonce(self::NONCE),
		]);
		wp_enqueue_style('octopus-notices', OCTOPUS_URL . 'assets/notices.css', [], filemtime(OCTOPUS_PATH . 'assets/notices.css'));
	}

	/**
	 * Clears the recent errors when the notice is
	 * dismissed, by an administrator.
	 *
	 * @since 1.0.0
	 */
	public function dismiss()
	{
		check_ajax_referer(self::NONCE);
		if (!current_user_can('manage_options')) {
			wp_send_json_error(null, 403);
		}
		Errors::clear_recent();
		wp_send_json_success();
	}

	/**
	 * Adds the errors of the attachment, as HTML, to
	 * the data sent to the media library, which
	 * receives it in the upload response.
	 *
	 * @param array $response
	 * @param \WP_Post $attachment
	 * @return array
	 * @since 1.0.0
	 */
	public function attachment_for_js($response, $attachment)
	{
		if (!current_user_can('edit_post', $attachment->ID)) {
			return $response;
		}
		$errors = Errors::for_attachment($attachment->ID);
		if (!empty($errors)) {
			$response['octopusErrors'] = $this->items_html($errors);
		}
		return $response;
	}
}
