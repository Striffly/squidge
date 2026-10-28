/**
 * components/MediaQueue.js
 *
 * The actions of OIO in the media library, list mode
 * (body.js-octopus-media): optimise an image, through
 * the queue of the media library, and restore its
 * original, without leaving the page. From the click,
 * the cell of the image is busy and the link takes its
 * label while it runs (Optimising…), then its label
 * once done (Optimised!) for a moment: the cell and
 * the actions are then replaced by the ones PHP renders
 * again, escaped, followed while the image waits in
 * the queue. A request that fails follows its link, as
 * without JS.
 */

(() => {
	const {Component} = window.octopus;

	class MediaQueue extends Component {
		/** Between two looks at the images waiting. */
		static DELAY = 2000;
		/** How long a link says its action is done. */
		static DONE = 1500;

		vars() {
			super.vars();
			this.timer = null;
		}

		events() {
			super.events();
			this.addEvent({target: this.el, name: 'click', callback: this.onClick});
		}

		init() {
			super.init();
			// Images left waiting, e.g. the page loaded again meanwhile.
			this.follow();
		}

		/**
		 * @param {MouseEvent} event
		 */
		onClick(event) {
			const link = event.target.closest('.js-octopus-media-action');
			if (link) {
				event.preventDefault();
				this.send(link);
			}
		}

		/**
		 * @param {HTMLAnchorElement} link
		 */
		async send(link) {
			const {request, id} = link.dataset;
			const cell = this.cell(id);
			const actions = this.actions(id);
			// A second click waits for the first one.
			if (cell?.dataset.busy || actions?.dataset.busy || actions?.dataset.done) {
				return;
			}
			if (cell) {
				cell.dataset.busy = '1';
			}
			if (actions) {
				actions.dataset.busy = request;
			}
			link.textContent = link.dataset.labelBusy;
			let response = null;
			try {
				response = await this.view.api.post(request, {id});
			} catch {
				// Followed below.
			}
			if (!response?.success) {
				window.location.assign(link.href);
				return;
			}
			this.render(response.data);
			this.follow();
		}

		/**
		 * Replaces the cell and the actions of an image by
		 * the ones rendered by PHP, if they changed. An
		 * action that ran, and is done, first says so.
		 *
		 * @param {{id: number, cell: string, actions: string, pending: boolean, failed: boolean}} state
		 */
		render({id, cell, actions, pending, failed}) {
			this.replace(this.cell(id), cell);
			const current = this.actions(id);
			const link = current?.querySelector(`[data-request="${current.dataset.busy}"]`);
			if (!link || pending || failed) {
				this.replace(current, actions);
				return;
			}
			delete current.dataset.busy;
			current.dataset.done = '1';
			link.dataset.done = '1';
			link.textContent = link.dataset.labelDone;
			setTimeout(() => this.replace(this.actions(id), actions), MediaQueue.DONE);
		}

		/**
		 * @param {Element|null} element
		 * @param {string} html
		 */
		replace(element, html) {
			if (!element) {
				return;
			}
			// Busy from the click: the HTML of PHP says if it still is.
			delete element.dataset.busy;
			delete element.dataset.done;
			const fresh = document.createRange().createContextualFragment(html).firstElementChild;
			if (fresh && !element.isEqualNode(fresh)) {
				element.replaceWith(fresh);
			}
		}

		/**
		 * @param {string|number} id
		 * @returns {HTMLElement|null}
		 */
		cell(id) {
			return this.el.querySelector(`.js-octopus-media-cell[data-id="${id}"]`);
		}

		/**
		 * @param {string|number} id
		 * @returns {HTMLElement|null}
		 */
		actions(id) {
			return this.el.querySelector(`.js-octopus-media-actions[data-id="${id}"]`);
		}

		/**
		 * Asks the state of the images waiting, again
		 * until none is left.
		 */
		follow() {
			clearTimeout(this.timer);
			const ids = [...this.el.querySelectorAll('.js-octopus-media-cell[data-pending="1"]')].map((cell) => cell.dataset.id);
			if (!ids.length) {
				return;
			}
			this.timer = setTimeout(async () => {
				try {
					const response = await this.view.api.post('octopus_media_state', {ids: ids.join(',')});
					if (response.success) {
						response.data.forEach((state) => this.render(state));
					}
				} catch {
					// Asked again below.
				}
				this.follow();
			}, MediaQueue.DELAY);
		}

		destroy() {
			super.destroy();
			clearTimeout(this.timer);
		}
	}

	window.octopus.MediaQueue = MediaQueue;
})();
