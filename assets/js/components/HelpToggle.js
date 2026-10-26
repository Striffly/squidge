/**
 * components/HelpToggle.js
 *
 * The "?" of a field (.js-octopus-help-toggle), opening
 * its full help under its short note. Hidden without
 * JS, the full help then shown.
 */

(() => {
	const {Component} = window.octopus;

	class HelpToggle extends Component {
		getElems() {
			super.getElems();
			this.text = document.getElementById(this.el.getAttribute('aria-controls'));
		}

		events() {
			super.events();
			this.addEvent({target: this.el, name: 'click', callback: () => this.toggle(this.text.hidden)});
		}

		init() {
			super.init();
			this.toggle(false);
		}

		/**
		 * @param {boolean} open
		 */
		toggle(open) {
			this.text.hidden = !open;
			this.el.setAttribute('aria-expanded', String(open));
		}
	}

	window.octopus.HelpToggle = HelpToggle;
})();
