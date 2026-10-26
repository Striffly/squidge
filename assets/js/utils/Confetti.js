/**
 * utils/Confetti.js
 *
 * Confetti thrown from the top of an element holding a
 * .js-octopus-confetti layer, once all the images are
 * optimised. The pieces are made here: random, and
 * gone once fallen. None without animations.
 */

(() => {
	class Confetti {
		static #COLOURS = ['#ff3cac', '#7b2ff7', '#00e5ff', '#ffc53d', '#b78cff'];
		static #PIECES = 40;
		static #STEPS = 30;
		static #LIFETIME = 2000;

		/**
		 * @param {HTMLElement} element
		 */
		static throwFrom(element) {
			const layer = element.querySelector('.js-octopus-confetti');
			layer.replaceChildren();
			if (!layer.animate || matchMedia('(prefers-reduced-motion: reduce)').matches) {
				return;
			}
			for (let i = 0; i < Confetti.#PIECES; i++) {
				const piece = document.createElement('span');
				piece.className = 'octopus-confetti__piece';
				piece.style.setProperty('--x', `${10 + Math.random() * 80}%`);
				piece.style.setProperty('--color', Confetti.#COLOURS[i % Confetti.#COLOURS.length]);
				layer.append(piece);
				piece.animate(Confetti.#flight(), {
					duration: 1300 + Math.random() * 300,
					delay: Math.random() * 150,
					easing: 'linear',
					fill: 'forwards',
				});
			}
			setTimeout(() => layer.replaceChildren(), Confetti.#LIFETIME);
		}

		/**
		 * The keyframes of a piece: thrown up, it slows
		 * down, then falls back with gravity, swaying and
		 * flipping over, and fades out.
		 *
		 * @returns {Keyframe[]}
		 */
		static #flight() {
			const up = 280 + Math.random() * 180;
			const side = Math.random() * 160 - 80;
			const gravity = 520;
			const spin = Math.random() * 720 - 360;
			const sway = 6 + Math.random() * 10;
			const phase = Math.random() * Math.PI * 2;
			const fadeFrom = 12;

			return Array.from({length: Confetti.#STEPS + 1}, (_, step) => {
				const t = step / Confetti.#STEPS * 2.2;
				// Air slows the piece down sideways.
				const x = side * (1 - Math.exp(-1.5 * t)) / 1.5 + sway * Math.sin(t * 6 + phase);
				const y = -up * t + gravity * t * t / 2;
				return {
					transform: `translate(${x.toFixed(1)}px, ${y.toFixed(1)}px) rotate(${(spin * t).toFixed(0)}deg) scaleY(${Math.cos(t * 9 + phase).toFixed(2)})`,
					opacity: step < fadeFrom ? 1 : (Confetti.#STEPS - step) / (Confetti.#STEPS - fadeFrom),
				};
			});
		}
	}

	(window.octopus ??= {}).Confetti = Confetti;
})();
