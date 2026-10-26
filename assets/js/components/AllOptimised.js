/**
 * components/AllOptimised.js
 *
 * The message of the Optimise tab when the page opens
 * on a media library all optimised
 * (.js-octopus-all-optimised), with confetti the first
 * time it is seen. Not while a job runs, nor after it:
 * the result of the job already says it.
 */

(() => {
	const {Component, Confetti} = window.octopus;

	class AllOptimised extends Component {
		vars() {
			super.vars();
			this.celebrated = false;
		}

		events() {
			super.events();
			this.addEvent({target: this.view, name: 'job:status', callback: this.onStatus});
			this.addEvent({target: this.view, name: 'tab:shown', callback: this.celebrate});
		}

		init() {
			super.init();
			this.celebrate();
		}

		/**
		 * @param {CustomEvent} event
		 */
		onStatus({detail: {busy, seen, allOptimised}}) {
			this.el.hidden = busy || seen || !allOptimised;
			this.celebrate();
		}

		// Only once visible: its tab may be hidden.
		celebrate() {
			if (this.celebrated || this.el.hidden || !this.el.offsetParent) {
				return;
			}
			this.celebrated = true;
			Confetti.throwFrom(this.el);
		}
	}

	window.octopus.AllOptimised = AllOptimised;
})();
