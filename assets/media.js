/**
 * media.js
 *
 * Shows the Squidge errors of an image right after
 * it is uploaded to the media library, and clears
 * the recent errors when the notice is dismissed.
 */

(function ($) {
	var settings = window.squidgeMedia || {};

	function getNotice() {
		var $notice = $('.squidge-errors-notice');
		if ($notice.length) {
			return $notice;
		}
		$notice = $('<div class="notice notice-warning is-dismissible squidge-errors-notice"><p><strong>Squidge</strong> could not fully optimise some images:</p><ul></ul></div>');
		var $headerEnd = $('.wp-header-end').first();
		if ($headerEnd.length) {
			$notice.insertAfter($headerEnd);
		} else {
			$notice.prependTo($('.wrap').first());
		}
		// Adds the WordPress dismiss button.
		$(document).trigger('wp-notice-added');
		return $notice;
	}

	// Each error is HTML escaped and rendered by PHP, the same as the notice shown on page load.
	function showErrors(errors) {
		var $list = getNotice().find('ul');
		errors.forEach(function (html) {
			$('<li>').html(html).prependTo($list);
		});
	}

	$(function () {
		// The upload response is the attachment data, with the errors added by Squidge.
		if (window.wp && wp.Uploader && wp.Uploader.queue) {
			wp.Uploader.queue.on('change:uploading', function (attachment) {
				var errors = attachment.get('squidgeErrors');
				if (!attachment.get('uploading') && errors && errors.length) {
					showErrors(errors);
				}
			});
		}

		$(document).on('click', '.squidge-errors-notice .notice-dismiss', function () {
			$.post(window.ajaxurl, {action: settings.action, _ajax_nonce: settings.nonce});
		});
	});
})(jQuery);
