/**
 * plugins.js
 *
 * Warning of the OIO row of the plugins page,
 * before deactivating OIO: its originals are not
 * restored if it is then deleted, and the theme may
 * call octopus_image().
 */

(function () {
	var settings = window.octopusPlugins;
	var row = settings && document.querySelector('tr[data-plugin="' + settings.plugin + '"]');
	if (!row) {
		return;
	}
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	function dialog(title, html, buttons) {
		var element = document.createElement('dialog');
		element.className = 'octopus-dialog';
		element.innerHTML = '<h2></h2><div class="octopus-dialog__body"></div><div class="octopus-dialog__actions"></div>';
		element.querySelector('h2').textContent = title;
		element.querySelector('.octopus-dialog__body').innerHTML = html;
		buttons.forEach(function (button) {
			var tag = button.href ? 'a' : 'button';
			var action = document.createElement(tag);
			action.className = 'button' + (button.primary ? ' button-primary' : '');
			action.textContent = button.label;
			if (button.href) {
				action.href = button.href;
			} else {
				action.type = 'button';
				action.addEventListener('click', function () {
					element.close();
					if (button.onClick) {
						button.onClick();
					}
				});
			}
			element.querySelector('.octopus-dialog__actions').appendChild(action);
		});
		element.addEventListener('close', function () {
			element.remove();
		});
		document.body.appendChild(element);
		element.showModal();
	}

	// Shows a dialog instead of following a link, and follows it once confirmed.
	function intercept(link, show) {
		if (!link) {
			return;
		}
		var confirmed = false;
		link.addEventListener('click', function (event) {
			if (confirmed) {
				confirmed = false;
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			show(function () {
				confirmed = true;
				link.click();
			});
		}, true);
	}

	// WordPress only shows "Delete" for inactive plugins, when OIO no longer runs:
	// deactivating is the last moment to warn about the originals.
	intercept(row.querySelector('.deactivate a'), function (proceed) {
		var body = new URLSearchParams({action: 'octopus_uninstall_info', _ajax_nonce: settings.nonce});
		fetch(settings.ajaxurl, {method: 'POST', credentials: 'same-origin', body: body})
			.then(function (response) {
				return response.json();
			})
			.then(function (response) {
				var info = response.success ? response.data : {images: 0, size: ''};
				if (!info.images && !settings.themeCalls) {
					proceed();
					return;
				}
				var html = '';
				var buttons = [];
				var restore = __('Restore original images', 'octopus-image-optimizer');
				if (settings.themeCalls) {
					/* translators: %s: octopus_image() */
					var calls = '<strong>' + sprintf(__('Your theme calls %s', 'octopus-image-optimizer'), '<code>octopus_image()</code>') + '</strong>';
					/* translators: %s: Your theme calls octopus_image() */
					html += '<p>' + sprintf(__('%s: the pages using it will break while OIO is deactivated.', 'octopus-image-optimizer'), calls) + '</p>';
				}
				if (info.images) {
					var folder = '<code>uploads/octopus-originals/</code>' + (info.size ? ' (' + info.size + ')' : '');
					/* translators: %s: the number of images. */
					var images = '<strong>' + sprintf(_n('%s image', '%s images', info.images, 'octopus-image-optimizer'), Number(info.images).toLocaleString()) + '</strong>';
					/* translators: 1: the number of images, 2: the folder of the originals and its size. */
					html += '<p>' + sprintf(__('OIO has optimised %1$s. Deactivating or deleting OIO keeps them as they are: compressed, with their WebP and AVIF versions, and their originals in %2$s.', 'octopus-image-optimizer'), images, folder) + '</p>';
					/* translators: %s: Restore original images */
					html += '<p>' + sprintf(__('If you are going to delete OIO, run %s first to get your original images back and remove every OIO file.', 'octopus-image-optimizer'), '<strong>' + restore + '</strong>') + '</p>';
					buttons.push({label: restore, href: settings.restoreUrl, primary: true});
				}
				buttons.push({label: __('Deactivate anyway', 'octopus-image-optimizer'), onClick: proceed});
				buttons.push({label: __('Cancel', 'octopus-image-optimizer'), primary: !info.images});
				dialog(__('Before deactivating OIO', 'octopus-image-optimizer'), html, buttons);
			});
	});
})();
