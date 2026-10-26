/**
 * components/DependentField.js
 *
 * A field shown while another one has a value
 * (.js-octopus-dependent), e.g. the lossy quality
 * while lossy: data-when names the other field,
 * data-when-value its value.
 */

(() => {
	const {Component} = window.octopus;

	class DependentField extends Component {
		getElems() {
			super.getElems();
			this.inputs = this.view.el.querySelectorAll(`[name="${this.el.dataset.when}"]`);
		}

		events() {
			super.events();
			this.inputs.forEach((input) => this.addEvent({target: input, name: 'change', callback: this.update}));
		}

		init() {
			super.init();
			this.update();
		}

		update() {
			const checked = [...this.inputs].find((input) => input.checked);
			this.el.hidden = checked?.value !== this.el.dataset.whenValue;
		}
	}

	window.octopus.DependentField = DependentField;
})();
