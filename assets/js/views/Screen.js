/**
 * views/Screen.js
 *
 * A screen of the admin: builds a component on each
 * element with its js- class, then starts them. The
 * components talk through its events, e.g. tab:shown,
 * and share its API and its announcer for screen
 * readers.
 */

(() => {
	const {Api} = window.octopus;

	class Screen extends EventTarget {
		/**
		 * @param {HTMLElement} el
		 * @param {Array<{name: string, Class: Function}>} components A js- class, and the component built on its elements.
		 * @param {{ajaxurl: string, nonce: string}} settings
		 */
		constructor(el, components, settings) {
			super();
			this.el = el;
			this.settings = settings;
			this.api = new Api(settings);
			this.announcer = el.querySelector('.js-octopus-announcer');
			this.components = components.flatMap(({name, Class}) => [...el.querySelectorAll(`.${name}`)].map((element) => new Class(element, this)));
			this.components.forEach((component) => component.init());
		}

		/**
		 * @param {string} name
		 * @param {*} detail
		 */
		emit(name, detail = null) {
			this.dispatchEvent(new CustomEvent(name, {detail}));
		}

		/**
		 * Tells screen readers.
		 *
		 * @param {string} text
		 */
		announce(text) {
			if (this.announcer) {
				this.announcer.textContent = text;
			}
		}
	}

	(window.octopus ??= {}).Screen = Screen;
})();
