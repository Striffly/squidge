/**
 * components/JobStart.js
 *
 * A button starting a job (.js-octopus-job-start),
 * data-job naming it, data-confirm asking first if
 * set. Off while a job runs, when it has no image to
 * process, or while its box is not ticked: the
 * maintenance jobs need theirs (.js-octopus-job-confirm).
 * Off as the page opens, until the first status of the
 * jobs tells, and from the click until the job started.
 * The job box shows from the click on (job:starting):
 * starting a job takes about a second.
 */

(() => {
	const {__} = wp.i18n;
	const {Component} = window.octopus;

	class JobStart extends Component {
		/** The job each button runs, see JobsAjax::JOBS. */
		static ACTIONS = {new: 'optimise', outdated: 'optimise', all: 'optimise', remove_formats: 'remove_formats', restore: 'restore'};

		vars() {
			super.vars();
			this.name = this.el.dataset.job;
			this.busy = false;
			this.count = null;
			this.starting = false;
		}

		getElems() {
			super.getElems();
			this.confirmBox = this.view.el.querySelector(`.js-octopus-job-confirm[data-confirm-for="${this.name}"]`);
		}

		events() {
			super.events();
			this.addEvent({target: this.el, name: 'click', callback: this.start});
			this.addEvent({target: this.confirmBox, name: 'change', callback: this.update});
			this.addEvent({target: this.view, name: 'job:status', callback: this.onStatus});
		}

		init() {
			super.init();
			this.update();
		}

		/**
		 * @param {CustomEvent} event
		 */
		onStatus({detail: {counts, busy}}) {
			const images = {...counts, all: counts.total, remove_formats: counts.processed, restore: counts.processed};
			this.busy = busy;
			this.count = images[this.name];
			this.update();
		}

		update() {
			this.el.disabled = this.starting || this.count === null || this.busy || this.count === 0 || (this.confirmBox !== null && !this.confirmBox.checked);
		}

		async start() {
			if (this.el.dataset.confirm && !window.confirm(this.el.dataset.confirm)) {
				return;
			}
			this.starting = true;
			this.update();
			this.view.emit('job:starting', {action: JobStart.ACTIONS[this.name], total: this.count ?? 0});
			const response = await this.view.api.post('octopus_job_start', {job: this.name});
			this.starting = false;
			if (!response.success) {
				this.update();
				this.view.emit('job:start-failed');
				window.alert(response.data?.message ?? __('The job could not start.', 'octopus-image-optimizer'));
				return;
			}
			// The box is ticked again for the next time.
			if (this.confirmBox) {
				this.confirmBox.checked = false;
			}
			this.view.emit('job:started', response.data);
		}
	}

	window.octopus.JobStart = JobStart;
})();
