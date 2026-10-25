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

namespace OIO\Admin;

use OIO\Log\Errors;

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
	const NONCE = 'oio_dismiss_errors';

	/**
	 * Screens where the notice is shown: media library,
	 * add media, edit media and the options page.
	 *
	 * @var array
	 */
	const SCREENS = ['upload', 'media', 'attachment', 'settings_page_' . Fields::PAGE];

	/**
	 * What each library does, as shown to the user.
	 *
	 * @var array
	 */
	const ACTIONS = [
		'jpegoptim' => 'JPG compression',
		'optipng' => 'PNG compression',
		'cwebp' => 'WebP conversion',
		'avifenc' => 'AVIF conversion',
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
		'/permission denied|read-only|cannot (open|create|write)/i' => 'the file could not be written, check the permissions of the uploads folder.',
		'/premature end|corrupt|not a (jpeg|png)|recoverable errors|bad huffman|invalid/i' => 'the image file seems damaged or incomplete.',
		'/not found|code 127/i' => 'the library does not work on the server, check its installation.',
	];

	/**
	 * Adds the notice, its script and the errors to
	 * the attachment data sent after an upload.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
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
	 * @date 25/10/2026
	 */
	private function is_notice_screen()
	{
		$screen = get_current_screen();
		return $screen && in_array($screen->id, self::SCREENS, true) && current_user_can('upload_files');
	}

	/**
	 * Renders the recent errors as an admin notice.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function render()
	{
		if (!$this->is_notice_screen()) {
			return;
		}
		$errors = Errors::recent();
		if (empty($errors)) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible oio-errors-notice">
			<p><strong>OIO</strong> could not fully optimise some images:</p>
			<ul>
				<?php foreach ($this->items_html($errors) as $html) : ?>
					<li><?php echo $html; ?></li>
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
	 * @date 25/10/2026
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
			$action = array_key_exists($error['tool'], self::ACTIONS) ? self::ACTIONS[$error['tool']] : $error['tool'];
			$groups[$key]['actions'][$action] = $action;
			$groups[$key]['details'][] = sprintf('%s on %s: %s', $error['tool'], $error['file'], $error['message']);
		}

		$items = [];
		foreach ($groups as $group) {
			$items[] = sprintf(
				'%s &mdash; %s failed: %s <details class="oio-error-details"><summary>Technical details</summary><code>%s</code></details>',
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
	 * @date 25/10/2026
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
	 * @date 25/10/2026
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
	 * @date 25/10/2026
	 */
	public function scripts()
	{
		if (!$this->is_notice_screen()) {
			return;
		}
		wp_enqueue_script('oio-media', OIO_URL . 'assets/media.js', ['jquery'], filemtime(OIO_PATH . 'assets/media.js'), true);
		wp_localize_script('oio-media', 'oioMedia', [
			'action' => self::NONCE,
			'nonce' => wp_create_nonce(self::NONCE),
		]);
		wp_add_inline_style('wp-admin', '.oio-error-details{display:inline}.oio-error-details summary{display:inline;margin-left:4px;color:#646970;font-size:12px;cursor:pointer}.oio-error-details[open]{display:block}.oio-error-details code{display:block;margin:4px 0 0;font-size:12px}');
	}

	/**
	 * Clears the recent errors when the notice is
	 * dismissed.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function dismiss()
	{
		check_ajax_referer(self::NONCE);
		if (!current_user_can('upload_files')) {
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
	 * @date 25/10/2026
	 */
	public function attachment_for_js($response, $attachment)
	{
		if (!current_user_can('upload_files')) {
			return $response;
		}
		$errors = Errors::for_attachment($attachment->ID);
		if (!empty($errors)) {
			$response['oioErrors'] = $this->items_html($errors);
		}
		return $response;
	}
}
