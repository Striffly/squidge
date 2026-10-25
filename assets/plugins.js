/**
 * plugins.js
 *
 * Warning of the Squidge row of the plugins page,
 * before deactivating Squidge: its originals are not
 * restored if it is then deleted, and the theme may
 * call squidge_image().
 */

(function () {
	var settings = window.squidgePlugins;
	var row = settings && document.querySelector('tr[data-plugin="' + settings.plugin + '"]');
	if (!row) {
		return;
	}

	function dialog(title, html, buttons) {
		var element = document.createElement('dialog');
		element.className = 'squidge-dialog';
		element.innerHTML = '<h2></h2><div class="squidge-dialog__body"></div><div class="squidge-dialog__actions"></div>';
		element.querySelector('h2').textContent = title;
		element.querySelector('.squidge-dialog__body').innerHTML = html;
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
			element.querySelector('.squidge-dialog__actions').appendChild(action);
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

	// WordPress only shows "Delete" for inactive plugins, when Squidge no longer runs:
	// deactivating is the last moment to warn about the originals.
	intercept(row.querySelector('.deactivate a'), function (proceed) {
		var body = new URLSearchParams({action: 'squidge_uninstall_info', _ajax_nonce: settings.nonce});
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
				if (settings.themeCalls) {
					html += '<p><strong>Your theme calls <code>squidge_image()</code></strong>: the pages using it will break while Squidge is deactivated.</p>';
				}
				if (info.images) {
					html += '<p>Squidge has optimised <strong>' + Number(info.images).toLocaleString() + ' images</strong>.'
						+ ' Deactivating or deleting Squidge keeps them as they are: compressed, with their WebP and AVIF versions,'
						+ ' and their originals in <code>uploads/squidge-originals/</code>' + (info.size ? ' (' + info.size + ')' : '') + '.</p>'
						+ '<p>If you are going to delete Squidge, run <strong>Restore original images</strong> first to get your original'
						+ ' images back and remove every Squidge file.</p>';
					buttons.push({label: 'Restore original images', href: settings.restoreUrl, primary: true});
				}
				buttons.push({label: 'Deactivate anyway', onClick: proceed});
				buttons.push({label: 'Cancel', primary: !info.images});
				dialog('Before deactivating Squidge', html, buttons);
			});
	});
})();
