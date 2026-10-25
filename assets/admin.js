/**
 * admin.js
 *
 * Tabs for the Squidge options page. Every panel stays
 * in the same form, tabs only toggle their visibility.
 * Without JS, all panels are shown one after another.
 */

(function () {
	var page = document.querySelector('.squidge-page');
	if (!page) {
		return;
	}

	var tabs = page.querySelectorAll('.squidge-tabs [data-tab]');
	var panels = page.querySelectorAll('[data-panel]');
	var storageKey = 'squidge-tab';

	function exists(name) {
		return !!page.querySelector('[data-panel="' + name + '"]');
	}

	function show(name) {
		if (!exists(name)) {
			name = 'overview';
		}
		tabs.forEach(function (tab) {
			var active = tab.getAttribute('data-tab') === name;
			tab.classList.toggle('nav-tab-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
		});
		panels.forEach(function (panel) {
			panel.hidden = panel.getAttribute('data-panel') !== name;
		});
		page.setAttribute('data-active-tab', name);
		try {
			sessionStorage.setItem(storageKey, name);
		} catch (e) {}
	}

	page.addEventListener('click', function (event) {
		var link = event.target.closest('a[data-tab]');
		if (!link) {
			return;
		}
		event.preventDefault();
		var name = link.getAttribute('data-tab');
		show(name);
		history.replaceState(null, '', '#' + name);
	});

	// The hash is lost on the redirect after saving, so fall back to the last tab.
	var initial = location.hash.slice(1);
	if (!initial) {
		try {
			initial = sessionStorage.getItem(storageKey);
		} catch (e) {}
	}
	page.classList.add('squidge-js');
	show(initial || 'overview');
})();
