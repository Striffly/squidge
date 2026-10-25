/**
 * admin.js
 *
 * Tabs for the OIO options page. Every panel stays
 * in the same form, tabs only toggle their visibility.
 * Without JS, all panels are shown one after another.
 */

(function () {
	var page = document.querySelector('.oio-page');
	if (!page) {
		return;
	}

	var tabs = page.querySelectorAll('.oio-tabs [data-tab]');
	var panels = page.querySelectorAll('[data-panel]');
	var storageKey = 'oio-tab';

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
	page.classList.add('oio-js');
	show(initial || 'overview');
})();

/**
 * Background job of the media library card: starts it,
 * shows its progress until it ends, pauses, resumes and
 * cancels it, and keeps the counts up to date.
 */
(function () {
	var box = document.querySelector('.oio-job');
	var settings = window.oioJob;
	if (!box || !settings) {
		return;
	}

	var label = box.querySelector('.oio-job__label');
	var bar = box.querySelector('.oio-job__bar');
	var result = box.querySelector('.oio-job__result');
	var timer = null;
	// Set while a job runs on this page, to report its end.
	var following = false;
	var LABELS = {optimise: 'Optimising', remove_formats: 'Removing WebP and AVIF', restore: 'Restoring the originals'};
	var CONFIRMS = {
		all: 'Re-optimise all images from their originals, with their thumbnails? This can take a while.',
		remove_formats: 'Delete the WebP and AVIF files of all images?',
		restore: 'Restore the original images and delete every file made by OIO? The images will no longer be optimised.'
	};

	function request(action, data) {
		var body = new URLSearchParams(Object.assign({action: action, _ajax_nonce: settings.nonce}, data || {}));
		return fetch(settings.ajaxurl, {method: 'POST', credentials: 'same-origin', body: body})
			.then(function (response) {
				return response.json();
			});
	}

	function number(value) {
		return Number(value).toLocaleString();
	}

	function showCounts(counts) {
		counts['new'] = counts['to-optimise'] + counts.error;
		document.querySelectorAll('[data-count]').forEach(function (element) {
			var name = element.getAttribute('data-count');
			if (name in counts) {
				element.textContent = number(counts[name]);
			}
		});
		box.querySelectorAll('[data-job]').forEach(function (button) {
			var name = button.getAttribute('data-job');
			var count = {all: counts.total, remove_formats: counts.processed, restore: counts.processed}[name];
			button.disabled = (count === undefined ? counts[name] : count) === 0;
		});
	}

	function render(data) {
		var job = data.job;
		var state = job && (job.state === 'running' || job.state === 'paused') ? job.state : 'idle';
		box.hidden = false;
		box.setAttribute('data-state', state);
		showCounts(data.counts);

		if (state !== 'idle') {
			following = true;
			var percent = job.total ? Math.round(job.done / job.total * 100) : 0;
			bar.value = percent;
			label.textContent = (state === 'paused' ? 'Paused: ' : (LABELS[job.action] || 'Processing') + '… ')
				+ number(job.done) + ' / ' + number(job.total) + ' images'
				+ (job.failed ? ', ' + number(job.failed) + ' with errors' : '');
			box.removeAttribute('data-result');
		} else if (following && job && (job.state === 'finished' || job.state === 'cancelled')) {
			following = false;
			result.textContent = (job.state === 'cancelled' ? 'Cancelled after ' : 'Done: ')
				+ number(job.done) + ' / ' + number(job.total) + ' images processed'
				+ (job.failed ? ', ' + number(job.failed) + ' with errors (see the Media library)' : '')
				+ (job.missing ? ', ' + number(job.missing) + ' had no original to restore, they stay compressed' : '')
				+ '.';
			box.setAttribute('data-result', '');
		}

		clearTimeout(timer);
		timer = state === 'idle' ? null : setTimeout(refresh, state === 'running' ? 2000 : 5000);
	}

	function refresh() {
		request('oio_job_status').then(function (response) {
			if (response.success) {
				render(response.data);
			}
		});
	}

	box.addEventListener('click', function (event) {
		var start = event.target.closest('[data-job]');
		var control = event.target.closest('[data-control]');
		if (start) {
			var job = start.getAttribute('data-job');
			if (CONFIRMS[job] && !window.confirm(CONFIRMS[job])) {
				return;
			}
			request('oio_job_start', {job: job}).then(function (response) {
				if (response.success) {
					render(response.data);
				} else {
					window.alert(response.data && response.data.message ? response.data.message : 'The job could not start.');
				}
			});
		} else if (control) {
			var command = control.getAttribute('data-control');
			if (command === 'cancel' && !window.confirm('Cancel the job? The images already processed stay processed.')) {
				return;
			}
			request('oio_job_control', {command: command}).then(function (response) {
				if (response.success) {
					render(response.data);
				}
			});
		}
	});

	refresh();
})();
