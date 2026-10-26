/**
 * components/Component.js
 *
 * The base of every component of OIO: a class bound to
 * an element of the page, found by its js- class (see
 * the component list of each screen: settings.js,
 * notices.js, plugins.js). Its steps, in order:
 * - vars(): its state;
 * - bindMethods(): the methods passed as callbacks;
 * - getElems(): its elements, by their js- classes;
 * - events(): its events, with addEvent();
 * - init(): its first render, called by the screen
 *   once all its components are built, so that they
 *   all hear the first events.
 *
 * Every class of OIO is in its own file, registered on
 * window.octopus: components/, utils/ and views/. The
 * script of each screen starts them.
 */

(() => {
	class Component {
		/**
		 * @param {HTMLElement} el The element of the component.
		 * @param {Object} view The screen holding it (views/Screen.js).
		 */
		constructor(el, view) {
			this.el = el;
			this.view = view;
			this.eventList = [];

			this.vars();
			this.bindMethods();
			this.getElems();
			this.events();
		}

		vars() {}

		bindMethods() {}

		getElems() {}

		events() {}

		init() {}

		/**
		 * Adds an event, removed by destroy().
		 *
		 * @param {Object} options
		 * @param {EventTarget} options.target The element, or the screen for its events.
		 * @param {string} options.name
		 * @param {Function} options.callback Called with the component as this.
		 * @param {boolean} [options.capture=false]
		 */
		addEvent({target, name, callback, capture = false}) {
			if (!target) {
				return;
			}
			const fn = callback.bind(this);
			target.addEventListener(name, fn, capture);
			this.eventList.push({target, name, fn, capture});
		}

		destroy() {
			for (const {target, name, fn, capture} of this.eventList) {
				target.removeEventListener(name, fn, capture);
			}
			this.eventList = [];
		}
	}

	(window.octopus ??= {}).Component = Component;
})();
