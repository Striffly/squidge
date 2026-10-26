/**
 * components/Wallet.js
 *
 * The Bitcoin address of the support, at the bottom of
 * every tab (.js-octopus-wallet), behind its button.
 * Shown without JS, and open when the page opens on
 * #support: the link of the plugins page to buy a
 * coffee.
 */

(() => {
	const {Component} = window.octopus;

	class Wallet extends Component {
		getElems() {
			super.getElems();
			this.toggleButton = this.view.el.querySelector(`.js-octopus-wallet-toggle[aria-controls="${this.el.id}"]`);
		}

		events() {
			super.events();
			this.addEvent({target: this.toggleButton, name: 'click', callback: () => this.toggle(this.el.hidden)});
		}

		init() {
			super.init();
			const asked = location.hash === '#support';
			this.toggle(asked);
			if (asked) {
				this.el.scrollIntoView({block: 'center'});
			}
		}

		/**
		 * @param {boolean} open
		 */
		toggle(open) {
			this.el.hidden = !open;
			this.toggleButton.setAttribute('aria-expanded', String(open));
		}
	}

	window.octopus.Wallet = Wallet;
})();
