/**
 * components/JobStart.js
 *
 * A button starting a job (.js-octopus-job-start),
 * data-job naming it, data-confirm asking first if
 * set. Off while a job runs, when it has no image to
 * process, or while its box is not ticked: the
 * maintenance jobs need theirs (.js-octopus-job-confirm).
 */

(() => {
	const {__} = wp.i18n;
	const {Component} = window.octopus;

	class JobStart extends Component {
		vars() {
			super.vars();
			this.name = this.el.dataset.job;
			this.busy = false;
			this.count = null;
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
			this.el.disabled = this.busy || this.count === 0 || (this.confirmBox !== null && !this.confirmBox.checked);
		}

		async start() {
			if (this.el.dataset.confirm && !window.confirm(this.el.dataset.confirm)) {
				return;
			}
			const response = await this.view.api.post('octopus_job_start', {job: this.name});
			if (!response.success) {
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
