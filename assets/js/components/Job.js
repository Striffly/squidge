/**
 * components/Job.js
 *
 * The background job of the media library, in its box
 * above every tab (.js-octopus-job): follows it while
 * it runs or pauses, pauses, resumes and cancels it,
 * then sums up what it did, with confetti once all the
 * images are optimised.
 *
 * Emits job:status at each refresh, with the counts
 * and the bytes saved; renders the job a JobStart
 * starts at once on job:starting, as it is on
 * job:started, and as it was if it could not start.
 */

(() => {
	const {__, _n, sprintf} = wp.i18n;
	const {Component, Confetti} = window.octopus;

	class Job extends Component {
		/** The refresh, by state of the job: none once it ended. */
		static DELAYS = {running: 2000, paused: 5000};

		/**
		 * The texts of each job: while it runs, done,
		 * cancelled, and what it did to the images.
		 */
		static TEXTS = {
			optimise: {
				label: __('Optimising', 'octopus-image-optimizer'),
				done: __('Optimisation complete', 'octopus-image-optimizer'),
				cancelled: __('Optimisation cancelled', 'octopus-image-optimizer'),
				/* translators: %s: the number of images. */
				processed: (count) => sprintf(_n('%s image optimised', '%s images optimised', count, 'octopus-image-optimizer'), count.toLocaleString()),
			},
			remove_formats: {
				label: __('Removing WebP and AVIF', 'octopus-image-optimizer'),
				done: __('WebP and AVIF files removed', 'octopus-image-optimizer'),
				cancelled: __('Removal cancelled', 'octopus-image-optimizer'),
				/* translators: %s: the number of images. */
				processed: (count) => sprintf(_n('%s image cleaned up', '%s images cleaned up', count, 'octopus-image-optimizer'), count.toLocaleString()),
			},
			restore: {
				label: __('Restoring the originals', 'octopus-image-optimizer'),
				done: __('Original images restored', 'octopus-image-optimizer'),
				cancelled: __('Restore cancelled', 'octopus-image-optimizer'),
				/* translators: %s: the number of images. */
				processed: (count) => sprintf(_n('%s image restored', '%s images restored', count, 'octopus-image-optimizer'), count.toLocaleString()),
			},
		};

		vars() {
			super.vars();
			this.state = 'idle';
			this.counts = null;
			// A job ran while the page was open, and the one followed to report its end.
			this.seen = false;
			this.following = false;
			this.timer = null;
		}

		getElems() {
			super.getElems();
			this.label = this.el.querySelector('.js-octopus-job-label');
			this.percent = this.el.querySelector('.js-octopus-job-percent');
			this.bar = this.el.querySelector('.js-octopus-job-bar');
			this.controls = this.el.querySelectorAll('.js-octopus-job-control');
			this.result = this.el.querySelector('.js-octopus-job-result');
			this.title = this.el.querySelector('.js-octopus-job-title');
			this.detail = this.el.querySelector('.js-octopus-job-detail');
			this.errors = this.el.querySelector('.js-octopus-job-errors');
			this.errorsCount = this.el.querySelector('.js-octopus-job-errors-count');
			this.missing = this.el.querySelector('.js-octopus-job-missing');
		}

		events() {
			super.events();
			this.controls.forEach((control) => this.addEvent({target: control, name: 'click', callback: () => this.control(control.dataset.control)}));
			this.addEvent({target: this.view, name: 'job:starting', callback: this.onStarting});
			this.addEvent({target: this.view, name: 'job:started', callback: this.onStarted});
			this.addEvent({target: this.view, name: 'job:start-failed', callback: this.refresh});
			// A quality changed, and images are optimised with older settings.
			this.addEvent({target: this.view, name: 'settings:saved', callback: this.refresh});
		}

		init() {
			super.init();
			this.refresh();
		}

		async refresh() {
			const response = await this.view.api.post('octopus_job_status');
			if (response.success) {
				this.render(response.data);
				return;
			}
			// Not answered, e.g. PHP restarting: asked again, the page goes on following the job.
			clearTimeout(this.timer);
			this.timer = setTimeout(() => this.refresh(), Job.DELAYS[this.state] ?? Job.DELAYS.running);
		}

		/**
		 * Shows the job at 0 % while it starts.
		 *
		 * @param {CustomEvent} event With the action and the number of images of the job.
		 */
		onStarting({detail: {action, total}}) {
			this.el.hidden = false;
			this.el.dataset.state = 'running';
			this.showProgress({action, total, state: 'running', done: 0, failed: 0});
		}

		/**
		 * @param {CustomEvent} event With the status of the job started.
		 */
		onStarted({detail}) {
			this.render(detail);
			this.el.scrollIntoView({behavior: 'smooth', block: 'center'});
		}

		/**
		 * @param {'pause'|'resume'|'cancel'|'dismiss'} command
		 */
		async control(command) {
			if (command === 'dismiss') {
				this.dismiss();
				return;
			}
			if (command === 'cancel' && !window.confirm(__('Cancel the job? The images already processed stay processed.', 'octopus-image-optimizer'))) {
				return;
			}
			const response = await this.view.api.post('octopus_job_control', {command});
			if (response.success) {
				this.render(response.data);
				return;
			}
			// Never lost without a word: the job goes on until the click reaches the site.
			window.alert(response.data?.message ?? __('The site did not answer: try again.', 'octopus-image-optimizer'));
		}

		/**
		 * @param {{job: ?Object, counts: Object<string, number>, savings: Object<string, string>}} data
		 */
		render({job, counts, savings}) {
			this.state = ['running', 'paused'].includes(job?.state) ? job.state : 'idle';
			const busy = this.state !== 'idle';
			this.seen ||= busy;
			this.counts = {...counts, new: counts['to-optimise'] + counts.error};

			this.el.hidden = false;
			this.el.dataset.state = this.state;
			if (busy) {
				this.following = true;
				this.showProgress(job);
			} else if (this.following && ['finished', 'cancelled'].includes(job?.state)) {
				this.following = false;
				this.showResult(job);
			}

			this.view.emit('job:status', {counts: this.counts, savings, busy, seen: this.seen, allOptimised: this.allOptimised()});

			clearTimeout(this.timer);
			this.timer = busy ? setTimeout(() => this.refresh(), Job.DELAYS[this.state]) : null;
		}

		/**
		 * @param {Object} job
		 */
		showProgress(job) {
			const percent = job.total ? Math.round(job.done / job.total * 100) : 0;
			this.bar.value = percent;
			this.percent.textContent = `${percent} %`;

			/* translators: 1: the number of images processed, 2: the number of images. */
			let progress = sprintf(_n('%1$s / %2$s image', '%1$s / %2$s images', job.total, 'octopus-image-optimizer'), job.done.toLocaleString(), job.total.toLocaleString());
			if (job.failed) {
				/* translators: 1: the progress, e.g. 3 / 12 images, 2: the number of images with errors. */
				progress = sprintf(_n('%1$s, %2$s with errors', '%1$s, %2$s with errors', job.failed, 'octopus-image-optimizer'), progress, job.failed.toLocaleString());
			}
			if (job.waiting) {
				// Held by another request, e.g. a worker PHP stopped: its lock goes stale within a minute.
				/* translators: 1: the progress, e.g. 3 / 12 images, 2: a number of seconds. */
				progress = sprintf(_n('%1$s · an image waits for another request, %2$s second at most', '%1$s · an image waits for another request, %2$s seconds at most', job.waiting, 'octopus-image-optimizer'), progress, job.waiting.toLocaleString());
			}
			const doing = job.state === 'paused'
				? __('Paused:', 'octopus-image-optimizer')
				: `${Job.TEXTS[job.action]?.label ?? __('Processing', 'octopus-image-optimizer')}…`;
			this.label.textContent = `${doing} ${progress}`;
			this.dismiss();
		}

		/**
		 * Sums up a job that ended while the page followed it.
		 *
		 * @param {Object} job
		 */
		showResult(job) {
			const texts = Job.TEXTS[job.action] ?? Job.TEXTS.optimise;
			const cancelled = job.state === 'cancelled';

			this.title.textContent = cancelled ? texts.cancelled : texts.done;
			this.detail.textContent = cancelled ? Job.stopped(job) : `${Job.took(texts.processed(job.done - job.failed - job.missing), job)}.`;
			/* translators: %s: the number of images. */
			this.errorsCount.textContent = sprintf(_n('%s could not be optimised:', '%s could not be optimised:', job.failed, 'octopus-image-optimizer'), job.failed.toLocaleString());
			this.errors.hidden = !job.failed;
			/* translators: %s: the number of images. */
			this.missing.textContent = sprintf(_n('%s had no original to restore, it stays compressed.', '%s had no original to restore, they stay compressed.', job.missing, 'octopus-image-optimizer'), job.missing.toLocaleString());
			this.missing.hidden = !job.missing;

			this.el.dataset.result = cancelled ? 'cancelled' : (job.failed || job.missing ? 'warning' : 'success');
			if (job.action === 'optimise' && !cancelled && this.allOptimised()) {
				Confetti.throwFrom(this.result);
			}
		}

		dismiss() {
			delete this.el.dataset.result;
		}

		allOptimised() {
			return this.counts.total > 0 && this.counts.optimised === this.counts.total;
		}

		/**
		 * @param {Object} job
		 * @returns {string}
		 */
		static stopped(job) {
			/* translators: 1: the number of images processed, 2: the number of images. */
			const stopped = sprintf(_n('Stopped after %1$s of %2$s image', 'Stopped after %1$s of %2$s images', job.total, 'octopus-image-optimizer'), job.done.toLocaleString(), job.total.toLocaleString());
			return `${Job.took(stopped, job)}. ${__('The images already processed stay processed.', 'octopus-image-optimizer')}`;
		}

		/**
		 * What was done, and how long it took if known.
		 *
		 * @param {string} text
		 * @param {Object} job
		 * @returns {string}
		 */
		static took(text, job) {
			if (!job.finished || !job.started) {
				return text;
			}
			/* translators: 1: what was done, e.g. 12 images optimised, 2: a duration, e.g. 2 min 5 s. */
			return sprintf(__('%1$s in %2$s', 'octopus-image-optimizer'), text, Job.duration(job.finished - job.started));
		}

		/**
		 * @param {number} seconds
		 * @returns {string}
		 */
		static duration(seconds) {
			const minutes = Math.floor(seconds / 60);
			if (minutes >= 60) {
				/* translators: 1: hours, 2: minutes. */
				return sprintf(__('%1$d h %2$d min', 'octopus-image-optimizer'), Math.floor(minutes / 60), minutes % 60);
			}
			if (minutes) {
				/* translators: 1: minutes, 2: seconds. */
				return sprintf(__('%1$d min %2$d s', 'octopus-image-optimizer'), minutes, seconds % 60);
			}
			/* translators: %d: seconds. */
			return sprintf(__('%d s', 'octopus-image-optimizer'), Math.max(1, seconds));
		}

		destroy() {
			super.destroy();
			clearTimeout(this.timer);
		}
	}

	window.octopus.Job = Job;
})();
