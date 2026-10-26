/**
 * components/Tabs.js
 *
 * The tabs of the options page (.js-octopus-tabs).
 * Every panel stays in the same form, the tabs only
 * toggle their visibility; without JS, all panels
 * show one after another. The tab shown is kept in the
 * hash, and in the session for the redirect after
 * saving, which loses the hash.
 *
 * Shows a tab when a .js-octopus-tab-link is clicked,
 * or on tab:show, then emits tab:shown.
 */

(() => {
	const {Component} = window.octopus;

	class Tabs extends Component {
		static STORAGE_KEY = 'octopus-tab';
		static DEFAULT = 'overview';

		vars() {
			super.vars();
			this.current = null;
		}

		getElems() {
			super.getElems();
			this.tabs = this.el.querySelectorAll('.js-octopus-tab');
			this.panels = this.view.el.querySelectorAll('.js-octopus-panel');
		}

		events() {
			super.events();
			this.addEvent({target: this.view.el, name: 'click', callback: this.onLinkClick});
			this.addEvent({target: this.view, name: 'tab:show', callback: ({detail}) => this.show(detail)});
		}

		init() {
			super.init();
			this.show(location.hash.slice(1) || Tabs.stored() || Tabs.DEFAULT);
		}

		/**
		 * Shows a tab, the overview if it does not exist.
		 *
		 * @param {string} name
		 */
		show(name) {
			if (![...this.panels].some((panel) => panel.dataset.panel === name)) {
				name = Tabs.DEFAULT;
			}
			this.tabs.forEach((tab) => {
				const active = tab.dataset.tab === name;
				tab.classList.toggle('octopus-tabs__tab--active', active);
				tab.setAttribute('aria-selected', String(active));
				if (active) {
					this.scrollToTab(tab);
				}
			});
			this.panels.forEach((panel) => {
				panel.hidden = panel.dataset.panel !== name;
			});
			this.current = name;
			Tabs.store(name);
			this.view.emit('tab:shown', name);
		}

		/**
		 * Follows a link to a tab, or to a part of it, e.g.
		 * the settings of a format, opening their fold.
		 *
		 * @param {MouseEvent} event
		 */
		onLinkClick(event) {
			const link = event.target.closest('.js-octopus-tab-link');
			if (!link) {
				return;
			}
			event.preventDefault();
			this.show(link.dataset.tab);
			history.replaceState(null, '', `#${this.current}`);

			const target = link.dataset.target && document.getElementById(link.dataset.target);
			if (target) {
				target.querySelectorAll('.js-octopus-fold').forEach((fold) => {
					fold.open = true;
				});
				target.scrollIntoView({behavior: 'smooth', block: 'start'});
			}
		}

		/**
		 * On small screens, the tabs scroll sideways: the
		 * active one comes into view.
		 *
		 * @param {HTMLElement} tab
		 */
		scrollToTab(tab) {
			if (this.el.scrollWidth > this.el.clientWidth) {
				this.el.scrollLeft = tab.offsetLeft - (this.el.clientWidth - tab.offsetWidth) / 2;
			}
		}

		// The session storage may be blocked: the tab is then not kept.
		static stored() {
			try {
				return sessionStorage.getItem(Tabs.STORAGE_KEY);
			} catch {
				return null;
			}
		}

		static store(name) {
			try {
				sessionStorage.setItem(Tabs.STORAGE_KEY, name);
			} catch {
				// Not kept.
			}
		}
	}

	window.octopus.Tabs = Tabs;
})();
