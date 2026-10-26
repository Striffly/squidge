/**
 * components/Cta.js
 *
 * The button to optimise, on the overview
 * (.js-octopus-cta): filled with the gradient, and
 * pulsing, while images wait for an optimisation.
 */

(() => {
	const {Component} = window.octopus;

	class Cta extends Component {
		events() {
			super.events();
			this.addEvent({target: this.view, name: 'job:status', callback: ({detail}) => this.update(detail.counts)});
		}

		/**
		 * @param {Object<string, number>} counts
		 */
		update(counts) {
			this.el.classList.toggle('octopus-cta--filled', counts.new + counts.outdated > 0);
		}
	}

	window.octopus.Cta = Cta;
})();
