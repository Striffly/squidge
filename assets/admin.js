/**
 * admin.js
 *
 * Tabs for the OIO options page, the "?" of the
 * fields opening their full help, and the sub-options
 * shown with their checkbox. Every panel stays
 * in the same form, tabs only toggle their visibility.
 * Without JS, all panels are shown one after another.
 */

(function () {
	var page = document.querySelector('.octopus-page');
	if (!page) {
		return;
	}

	var tabs = page.querySelectorAll('.octopus-tabs__tab');
	var panels = page.querySelectorAll('[data-panel]');
	var storageKey = 'octopus-tab';

	function exists(name) {
		return !!page.querySelector('[data-panel="' + name + '"]');
	}

	function show(name) {
		if (!exists(name)) {
			name = 'overview';
		}
		tabs.forEach(function (tab) {
			var active = tab.getAttribute('data-tab') === name;
			tab.classList.toggle('octopus-tabs__tab--active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
			// On small screens the tabs scroll sideways: the active one comes into view.
			var bar = tab.parentNode;
			if (active && bar.scrollWidth > bar.clientWidth) {
				bar.scrollLeft = tab.offsetLeft - (bar.clientWidth - tab.offsetWidth) / 2;
			}
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
			// Its folded settings open with it.
			target.querySelectorAll('.octopus-format__settings').forEach(function (fold) {
				fold.open = true;
			});
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
	page.classList.add('octopus-page--js');
	show(initial || 'overview');

	// The full help of a field, behind its "?" button.
	page.querySelectorAll('.octopus-help__text').forEach(function (text) {
		text.hidden = true;
	});
	page.addEventListener('click', function (event) {
		var toggle = event.target.closest('.octopus-help__toggle');
		if (!toggle) {
			return;
		}
		var open = toggle.getAttribute('aria-expanded') !== 'true';
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		document.getElementById(toggle.getAttribute('aria-controls')).hidden = !open;
	});

	// A sub-option shows while its checkbox is ticked, e.g. the lossy quality.
	function showSubOptions() {
		page.querySelectorAll('[data-parent]').forEach(function (field) {
			var parent = document.getElementById(field.getAttribute('data-parent'));
			field.hidden = !!parent && !parent.checked;
		});
	}
	page.addEventListener('change', function (event) {
		if (event.target.matches('.octopus-toggle__input')) {
			showSubOptions();
		}
	});
	showSubOptions();

	// A field the browser refuses on saving is shown: its tab, its folded settings.
	page.addEventListener('invalid', function (event) {
		var panel = event.target.closest('[data-panel]');
		if (panel && panel.hidden) {
			show(panel.getAttribute('data-panel'));
		}
		var fold = event.target.closest('details');
		if (fold) {
			fold.open = true;
		}
		var sub = event.target.closest('[data-parent]');
		if (sub) {
			sub.hidden = false;
		}
	}, true);
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
	var page = document.querySelector('.octopus-page');
	var box = document.querySelector('.octopus-job');
	var settings = window.octopusJob;
	if (!page || !box || !settings) {
		return;
	}

	var label = box.querySelector('.octopus-job__label');
	var bar = box.querySelector('.octopus-job__bar');
	var percentLabel = box.querySelector('.octopus-job__percent');
	var resultTitle = box.querySelector('.octopus-result__title');
	var resultDetail = box.querySelector('.octopus-result__detail');
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
		showDone(counts.total > 0 && counts.optimised === counts.total);
	}

	// All the images optimised: the message, with confetti the first time it
	// is seen on this page, and each time a job brings it.
	var done = page.querySelector('[data-done]');
	var celebrated = false;
	var COLOURS = ['#ff3cac', '#7b2ff7', '#00e5ff', '#ffc53d', '#b78cff'];

	function showDone(isDone) {
		if (!done) {
			return;
		}
		var appears = isDone && done.hidden;
		done.hidden = !isDone;
		if (appears) {
			celebrated = false;
		}
		celebrate();
	}

	function celebrate() {
		// Only once visible: the Optimise tab may be hidden.
		if (!done || done.hidden || celebrated || !done.offsetParent) {
			return;
		}
		celebrated = true;
		var confetti = done.querySelector('.octopus-done__confetti');
		confetti.textContent = '';
		if (!confetti.animate || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
			return;
		}
		for (var i = 0; i < 40; i++) {
			var piece = document.createElement('span');
			piece.className = 'octopus-done__piece';
			piece.style.setProperty('--x', (10 + Math.random() * 80) + '%');
			piece.style.setProperty('--color', COLOURS[i % COLOURS.length]);
			confetti.appendChild(piece);
			piece.animate(flight(), {duration: 1300 + Math.random() * 300, delay: Math.random() * 150, easing: 'linear', fill: 'forwards'});
		}
		setTimeout(function () {
			confetti.textContent = '';
		}, 2000);
	}

	// The keyframes of a piece of confetti: thrown up, it slows down, then
	// falls back with gravity, swaying and flipping over, and fades out.
	function flight() {
		var up = 280 + Math.random() * 180;
		var side = Math.random() * 160 - 80;
		var gravity = 520;
		var spin = Math.random() * 720 - 360;
		var sway = 6 + Math.random() * 10;
		var phase = Math.random() * Math.PI * 2;
		var frames = [];
		for (var step = 0; step <= 30; step++) {
			var t = step / 30 * 2.2;
			// Air slows the piece down sideways.
			var x = side * (1 - Math.exp(-1.5 * t)) / 1.5 + sway * Math.sin(t * 6 + phase);
			var y = -up * t + gravity * t * t / 2;
			frames.push({
				transform: 'translate(' + x.toFixed(1) + 'px, ' + y.toFixed(1) + 'px) rotate(' + (spin * t).toFixed(0) + 'deg) scaleY(' + Math.cos(t * 9 + phase).toFixed(2) + ')',
				opacity: step < 12 ? 1 : (30 - step) / 18
			});
		}
		return frames;
	}

	page.addEventListener('click', function (event) {
		if (event.target.closest('a[data-tab="optimise"]')) {
			setTimeout(celebrate);
		}
	});

	// The bytes saved, formatted by the server.
	function showSavings(savings) {
		var savingsBox = document.querySelector('.octopus-savings');
		if (!savingsBox || !savings) {
			return;
		}
		savingsBox.querySelector('.octopus-savings__main').hidden = !savings.images;
		savingsBox.querySelector('.octopus-savings__empty').hidden = !!savings.images;
		savingsBox.querySelectorAll('[data-savings]').forEach(function (element) {
			element.textContent = savings[element.getAttribute('data-savings')];
		});
		savingsBox.querySelector('.octopus-savings__hint').hidden = !savings.hint;
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
		page.classList.toggle('octopus-page--busy', busy);
		showCounts(data.counts);
		showSavings(data.savings);

		if (state !== 'idle') {
			following = true;
			var percent = job.total ? Math.round(job.done / job.total * 100) : 0;
			bar.value = percent;
			percentLabel.textContent = percent + ' %';
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
		request('octopus_job_status').then(function (response) {
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
		var control = event.target.closest('.octopus-job [data-control]');
		if (start) {
			var job = start.getAttribute('data-job');
			if (start.hasAttribute('data-confirm') && !window.confirm(start.getAttribute('data-confirm'))) {
				return;
			}
			request('octopus_job_start', {job: job}).then(function (response) {
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
			request('octopus_job_control', {command: command}).then(function (response) {
				if (response.success) {
					render(response.data);
				}
			});
		}
	});

	refresh();
})();

/**
 * Test of the delivery: asks the server to request an
 * optimised image as a browser, and lists the checks.
 * Their advice comes from the server, as HTML.
 */
(function () {
	var button = document.querySelector('[data-delivery-test]');
	var settings = window.octopusJob;
	if (!button || !settings) {
		return;
	}
	var result = document.querySelector('.octopus-test__result');
	var sample = result.querySelector('.octopus-test__sample');
	var list = result.querySelector('.octopus-checks');
	var summary = document.querySelector('.octopus-test__summary');

	// The verdict, next to the button: the results may be below the fold.
	function showSummary(text, state) {
		if (!summary) {
			return;
		}
		summary.textContent = text;
		summary.className = 'octopus-test__summary octopus-test__summary--' + state;
		summary.hidden = false;
	}

	button.addEventListener('click', function () {
		button.disabled = true;
		button.textContent = 'Testing…';
		var body = new URLSearchParams({action: 'octopus_delivery_test', _ajax_nonce: settings.nonce});
		fetch(settings.ajaxurl, {method: 'POST', credentials: 'same-origin', body: body})
			.then(function (response) {
				return response.json();
			})
			.then(function (response) {
				list.textContent = '';
				result.hidden = false;
				if (!response.success) {
					sample.textContent = response.data && response.data.message ? response.data.message : 'The test failed.';
					showSummary(sample.textContent, 'fail');
					return;
				}
				var failed = response.data.checks.filter(function (check) {
					return check.state === 'fail';
				}).length;
				var total = response.data.checks.length;
				showSummary(failed
					? failed + ' of ' + total + ' checks failed: see the results below.'
					: 'All ' + total + ' checks passed.', failed ? 'fail' : 'ok');
				sample.textContent = 'Tested on ' + response.data.sample + '.';
				response.data.checks.forEach(function (check) {
					var item = document.createElement('li');
					item.className = 'octopus-checks__item octopus-checks__item--' + check.state;
					item.innerHTML = '<span class="octopus-checks__icon dashicons" aria-hidden="true"></span>'
						+ '<span class="octopus-checks__label"></span><span class="octopus-checks__detail"></span><span class="octopus-checks__advice"></span>';
					item.querySelector('.octopus-checks__label').textContent = check.label;
					item.querySelector('.octopus-checks__detail').textContent = check.detail;
					item.querySelector('.octopus-checks__advice').innerHTML = check.advice;
					list.appendChild(item);
				});
			})
			.finally(function () {
				button.disabled = false;
				button.textContent = 'Run the test again';
			});
	});
})();

/**
 * Copy buttons on the code: the rules of the server,
 * the theme helper, the install commands. Copies with
 * the clipboard API, or selects the code where it is
 * not available (http without a secure context).
 */
(function () {
	var page = document.querySelector('.octopus-page');
	if (!page) {
		return;
	}
	var status = document.createElement('span');
	status.className = 'screen-reader-text';
	status.setAttribute('aria-live', 'polite');
	page.appendChild(status);

	function button(code, modifier) {
		var copy = document.createElement('button');
		copy.type = 'button';
		copy.className = 'octopus-copy' + (modifier ? ' octopus-copy--' + modifier : '');
		copy.textContent = 'Copy';
		copy.octopusCode = code;
		return copy;
	}

	// The blocks get their button at the top right, the commands after them.
	page.querySelectorAll('pre.octopus-code').forEach(function (pre) {
		var block = document.createElement('div');
		block.className = 'octopus-code-block';
		pre.parentNode.insertBefore(block, pre);
		block.append(pre, button(pre, ''));
	});
	page.querySelectorAll('.octopus-install__list code').forEach(function (code) {
		code.after(button(code, 'inline'));
	});

	function select(code) {
		var range = document.createRange();
		range.selectNodeContents(code);
		var selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange(range);
	}

	function copy(code) {
		var text = code.textContent;
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text).then(function () {
				return true;
			}, function () {
				return false;
			});
		}
		select(code);
		var done = false;
		try {
			done = document.execCommand('copy');
		} catch (e) {}
		return Promise.resolve(done);
	}

	page.addEventListener('click', function (event) {
		var target = event.target.closest('.octopus-copy');
		if (!target) {
			return;
		}
		copy(target.octopusCode).then(function (done) {
			if (!done) {
				// The code stays selected, to copy by hand.
				select(target.octopusCode);
			}
			target.textContent = done ? 'Copied' : 'Press Ctrl+C';
			status.textContent = done ? 'Copied to the clipboard.' : 'The code is selected: press Ctrl+C to copy it.';
			clearTimeout(target.octopusTimer);
			target.octopusTimer = setTimeout(function () {
				target.textContent = 'Copy';
				status.textContent = '';
			}, 2000);
		});
	});
})();
