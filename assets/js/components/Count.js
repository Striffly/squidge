/**
 * components/Count.js
 *
 * A count of images (.js-octopus-count), data-count
 * naming it: a status, new, total or processed. Kept
 * up to date while a job runs, rolling to its new
 * value: fast, then slowing down as it comes
 * (ease-out). At once without animations.
 */

(() => {
	const {Component} = window.octopus;

	class Count extends Component {
		static DURATION = 1500;

		vars() {
			super.vars();
			this.value = null;
			this.frame = null;
		}

		events() {
			super.events();
			this.addEvent({target: this.view, name: 'job:status', callback: ({detail}) => this.set(detail.counts[this.el.dataset.count])});
		}

		/**
		 * @param {number|undefined} value
		 */
		set(value) {
			if (value === undefined) {
				return;
			}
			// The first value is the one shown by the server.
			const from = this.value ?? value;
			this.value = value;
			cancelAnimationFrame(this.frame);
			if (from === value || matchMedia('(prefers-reduced-motion: reduce)').matches) {
				this.show(value);
				return;
			}
			const start = performance.now();
			const step = (now) => {
				const progress = Math.min(1, (now - start) / Count.DURATION);
				const eased = 1 - (1 - progress) ** 3;
				this.show(Math.round(from + (value - from) * eased));
				if (progress < 1) {
					this.frame = requestAnimationFrame(step);
				}
			};
			step(start);
		}

		/**
		 * @param {number} value
		 */
		show(value) {
			this.el.textContent = value.toLocaleString();
		}

		destroy() {
			super.destroy();
			cancelAnimationFrame(this.frame);
		}
	}

	window.octopus.Count = Count;
})();
