/**
 * components/Savings.js
 *
 * The bytes saved, at the top of the overview
 * (.js-octopus-savings), kept up to date while a job
 * runs. Formatted by the server, each in its
 * .js-octopus-savings-value, data-savings naming it.
 */

(() => {
	const {Component} = window.octopus;

	class Savings extends Component {
		getElems() {
			super.getElems();
			this.main = this.el.querySelector('.js-octopus-savings-main');
			this.empty = this.el.querySelector('.js-octopus-savings-empty');
			this.hint = this.el.querySelector('.js-octopus-savings-hint');
			this.values = this.el.querySelectorAll('.js-octopus-savings-value');
		}

		events() {
			super.events();
			this.addEvent({target: this.view, name: 'job:status', callback: ({detail}) => this.show(detail.savings)});
		}

		/**
		 * @param {?Object<string, string>} savings
		 */
		show(savings) {
			if (!savings) {
				return;
			}
			this.main.hidden = !savings.images;
			this.empty.hidden = Boolean(savings.images);
			this.hint.hidden = !savings.hint;
			this.values.forEach((value) => {
				value.textContent = savings[value.dataset.savings];
			});
		}
	}

	window.octopus.Savings = Savings;
})();
