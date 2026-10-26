/**
 * utils/Roll.js
 *
 * A number rolling to another: fast, then slowing down
 * as it comes (ease-out). For the counts of images
 * (Count.js) and the size saved (Savings.js). At once
 * without animations.
 */

(() => {
	class Roll {
		static #DURATION = 1500;

		/**
		 * Starts rolling.
		 *
		 * @param {number} from
		 * @param {number} to
		 * @param {function(number): void} show Called on each frame with the value, to last.
		 */
		constructor(from, to, show) {
			this.frame = null;
			if (from === to || matchMedia('(prefers-reduced-motion: reduce)').matches) {
				show(to);
				return;
			}
			const start = performance.now();
			const step = (now) => {
				// The time of a frame can come a little before the start.
				const progress = Math.min(1, Math.max(0, (now - start) / Roll.#DURATION));
				if (progress < 1) {
					show(from + (to - from) * (1 - (1 - progress) ** 3));
					this.frame = requestAnimationFrame(step);
				} else {
					show(to);
				}
			};
			step(start);
		}

		stop() {
			cancelAnimationFrame(this.frame);
		}
	}

	(window.octopus ??= {}).Roll = Roll;
})();
