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

	var tabs = page.querySelectorAll('.oio-tabs__tab');
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
			tab.classList.toggle('oio-tabs__tab--active', active);
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
		// A link to a part of a tab, e.g. the settings of a format.
		var target = link.getAttribute('data-target') && document.getElementById(link.getAttribute('data-target'));
		if (target) {
			target.scrollIntoView({behavior: 'smooth', block: 'start'});
		}
	});

	// The hash is lost on the redirect after saving, so fall back to the last tab.
	var initial = location.hash.slice(1);
	if (!initial) {
		try {
			initial = sessionStorage.getItem(storageKey);
		} catch (e) {}
	}
	page.classList.add('oio-page--js');
	show(initial || 'overview');
})();

/**
 * Background job of the media library: started from
 * the Optimise and Maintenance tabs, its progress is
 * shown above every tab until it ends. Pauses, resumes
 * and cancels it, and keeps the counts and the bytes
 * saved up to date. The maintenance jobs need their
 * box ticked first.
 */
(function () {
	var page = document.querySelector('.oio-page');
	var box = document.querySelector('.oio-job');
	var settings = window.oioJob;
	if (!page || !box || !settings) {
		return;
	}

	var label = box.querySelector('.oio-job__label');
	var bar = box.querySelector('.oio-job__bar');
	var resultTitle = box.querySelector('.oio-result__title');
	var resultDetail = box.querySelector('.oio-result__detail');
	var counts = null;
	var busy = false;
	var timer = null;
	// Set while a job runs on this page, to report its end.
	var following = false;
	var LABELS = {optimise: 'Optimising', remove_formats: 'Removing WebP and AVIF', restore: 'Restoring the originals'};
	// Title when the job is done, when it is cancelled, and what was done to each image.
	var RESULTS = {
		optimise: ['Optimisation complete', 'Optimisation cancelled', 'optimised'],
		remove_formats: ['WebP and AVIF files removed', 'Removal cancelled', 'cleaned up'],
		restore: ['Original images restored', 'Restore cancelled', 'restored']
	};
	var MEDIA_URL = settings.ajaxurl.replace('admin-ajax.php', 'upload.php?mode=list');

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

	// A job button is off while a job runs, when it has no image to process,
	// or while its box is not ticked.
	function updateButtons() {
		page.querySelectorAll('[data-job]').forEach(function (button) {
			var name = button.getAttribute('data-job');
			var count = counts ? {all: counts.total, remove_formats: counts.processed, restore: counts.processed}[name] : 1;
			if (count === undefined) {
				count = counts[name];
			}
			var box = page.querySelector('[data-confirm-for="' + name + '"]');
			button.disabled = busy || count === 0 || (box && !box.checked);
		});
	}

	function showCounts(data) {
		counts = data;
		counts['new'] = counts['to-optimise'] + counts.error;
		document.querySelectorAll('[data-count]').forEach(function (element) {
			var name = element.getAttribute('data-count');
			if (name in counts) {
				element.textContent = number(counts[name]);
			}
		});
		updateButtons();
	}

	// The bytes saved, formatted by the server.
	function showSavings(savings) {
		var savingsBox = document.querySelector('.oio-savings');
		if (!savingsBox || !savings) {
			return;
		}
		savingsBox.querySelector('.oio-savings__main').hidden = !savings.images;
		savingsBox.querySelector('.oio-savings__empty').hidden = !!savings.images;
		savingsBox.querySelectorAll('[data-savings]').forEach(function (element) {
			element.textContent = savings[element.getAttribute('data-savings')];
		});
		savingsBox.querySelector('.oio-savings__hint').hidden = !savings.hint;
	}

	function duration(seconds) {
		var minutes = Math.floor(seconds / 60);
		if (minutes >= 60) {
			return Math.floor(minutes / 60) + ' h ' + (minutes % 60) + ' min';
		}
		return minutes ? minutes + ' min ' + (seconds % 60) + ' s' : Math.max(1, seconds) + ' s';
	}

	// Sums up a job that ended while this page followed it.
	function showResult(job) {
		var texts = RESULTS[job.action] || RESULTS.optimise;
		var cancelled = job.state === 'cancelled';
		var processed = job.done - job.failed - job.missing;
		var took = job.finished && job.started ? ' in ' + duration(job.finished - job.started) : '';
		var detail = cancelled
			? 'Stopped after ' + number(job.done) + ' of ' + number(job.total) + ' images' + took + '. The images already processed stay processed.'
			: number(processed) + ' ' + (processed === 1 ? 'image' : 'images') + ' ' + texts[2] + took + '.';
		resultTitle.textContent = cancelled ? texts[1] : texts[0];
		resultDetail.textContent = detail;
		if (job.failed) {
			resultDetail.append(' ' + number(job.failed) + ' could not be optimised: ');
			var link = document.createElement('a');
			link.href = MEDIA_URL;
			link.textContent = 'see the errors in the Media library';
			resultDetail.append(link, '.');
		}
		if (job.missing) {
			resultDetail.append(' ' + number(job.missing) + ' had no original to restore, they stay compressed.');
		}
		box.setAttribute('data-result', cancelled ? 'cancelled' : (job.failed || job.missing ? 'warning' : 'success'));
	}

	function render(data) {
		var job = data.job;
		var state = job && (job.state === 'running' || job.state === 'paused') ? job.state : 'idle';
		box.hidden = false;
		box.setAttribute('data-state', state);
		busy = state !== 'idle';
		page.classList.toggle('oio-page--busy', busy);
		showCounts(data.counts);
		showSavings(data.savings);

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
			showResult(job);
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

	page.addEventListener('change', function (event) {
		if (event.target.matches('[data-confirm-for]')) {
			updateButtons();
		}
	});

	page.addEventListener('click', function (event) {
		var start = event.target.closest('[data-job]');
		var control = event.target.closest('.oio-job [data-control]');
		if (start) {
			var job = start.getAttribute('data-job');
			if (start.hasAttribute('data-confirm') && !window.confirm(start.getAttribute('data-confirm'))) {
				return;
			}
			request('oio_job_start', {job: job}).then(function (response) {
				if (response.success) {
					// The box is ticked again for the next time.
					var tick = page.querySelector('[data-confirm-for="' + job + '"]');
					if (tick) {
						tick.checked = false;
					}
					render(response.data);
					box.scrollIntoView({behavior: 'smooth', block: 'center'});
				} else {
					window.alert(response.data && response.data.message ? response.data.message : 'The job could not start.');
				}
			});
		} else if (control) {
			var command = control.getAttribute('data-control');
			if (command === 'dismiss') {
				box.removeAttribute('data-result');
				return;
			}
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
