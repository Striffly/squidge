/**
 * components/CopyButton.js
 *
 * A button copying its code (.js-octopus-copy): the
 * rules of the server, the theme helper, the install
 * commands, the Bitcoin address. Copies with the
 * clipboard API, or selects the code where it is not
 * available (http, without a secure context), to copy
 * by hand. Hidden without JS.
 */

(() => {
	const {__} = wp.i18n;
	const {Component} = window.octopus;

	class CopyButton extends Component {
		static RESET_DELAY = 2000;

		vars() {
			super.vars();
			this.timer = null;
		}

		getElems() {
			super.getElems();
			this.code = document.getElementById(this.el.getAttribute('aria-controls'));
		}

		events() {
			super.events();
			this.addEvent({target: this.el, name: 'click', callback: this.copy});
		}

		async copy() {
			const copied = await this.write();
			if (!copied) {
				// The code stays selected, to copy by hand.
				this.select();
			}
			this.el.textContent = copied ? __('Copied', 'octopus-image-optimizer') : __('Press Ctrl+C', 'octopus-image-optimizer');
			this.view.announce(copied
				? __('Copied to the clipboard.', 'octopus-image-optimizer')
				: __('The code is selected: press Ctrl+C to copy it.', 'octopus-image-optimizer'));

			clearTimeout(this.timer);
			this.timer = setTimeout(() => {
				this.el.textContent = __('Copy', 'octopus-image-optimizer');
				this.view.announce('');
			}, CopyButton.RESET_DELAY);
		}

		/**
		 * @returns {Promise<boolean>} Whether the code was copied.
		 */
		async write() {
			if (navigator.clipboard && window.isSecureContext) {
				try {
					await navigator.clipboard.writeText(this.code.textContent);
					return true;
				} catch {
					return false;
				}
			}
			this.select();
			try {
				return document.execCommand('copy');
			} catch {
				return false;
			}
		}

		select() {
			const range = document.createRange();
			range.selectNodeContents(this.code);
			const selection = window.getSelection();
			selection.removeAllRanges();
			selection.addRange(range);
		}

		destroy() {
			super.destroy();
			clearTimeout(this.timer);
		}
	}

	window.octopus.CopyButton = CopyButton;
})();
