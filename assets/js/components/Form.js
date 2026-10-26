/**
 * components/Form.js
 *
 * The form of the settings (.js-octopus-form): a field
 * the browser refuses on saving is brought into view,
 * with its tab, its fold, and the field itself if it
 * depends on another one.
 */

(() => {
	const {Component} = window.octopus;

	class Form extends Component {
		events() {
			super.events();
			// Invalid does not bubble: caught on its way down.
			this.addEvent({target: this.el, name: 'invalid', callback: this.onInvalid, capture: true});
		}

		/**
		 * @param {Event} event
		 */
		onInvalid({target}) {
			const panel = target.closest('.js-octopus-panel');
			if (panel?.hidden) {
				this.view.emit('tab:show', panel.dataset.panel);
			}
			const fold = target.closest('.js-octopus-fold');
			if (fold) {
				fold.open = true;
			}
			const dependent = target.closest('.js-octopus-dependent');
			if (dependent) {
				dependent.hidden = false;
			}
		}
	}

	window.octopus.Form = Form;
})();
