/**
 * components/Form.js
 *
 * The form of the settings (.js-octopus-form). Each
 * setting is saved as it changes (SettingsAjax): a box,
 * a choice or a list at once, a number once left or
 * entered, if the browser takes it. One at a time, in
 * the order of the changes: the server writes all the
 * settings at once, two saves at the same time would
 * undo each other. A mark by it tells it saved, or not,
 * its value then put back. Saved, but what follows it
 * failed (e.g. the rules of uploads/.htaccess not
 * written), the error shows under it until it is saved
 * again. What the page shows of the settings follows,
 * and of the images once a job ended: its parts marked
 * .js-octopus-live are taken again from the page, as
 * the server renders it, by their id, or only their
 * class with data-live="class". Without JS, the form is
 * sent with its buttons.
 *
 * A field the browser refuses is brought into view,
 * with its tab, its fold, and the field itself if it
 * depends on another one.
 */

(() => {
	const {__} = wp.i18n;
	const {Component} = window.octopus;

	class Form extends Component {
		static PREFIX = 'octopus_settings[';
		static REFRESH_DELAY = 300;
		// Between a mark gone and the next one, so that a save in a row shows as one.
		static MARK_GAP = 80;

		vars() {
			super.vars();
			this.reverting = false;
			this.refreshTimer = null;
			// One mark at a time, on the setting saved last.
			this.current = null;
			this.markTimer = null;
			this.busy = false;
			// The saves waiting for the one sent.
			this.saving = Promise.resolve();
		}

		events() {
			super.events();
			// Invalid does not bubble: caught on its way down.
			this.addEvent({target: this.el, name: 'invalid', callback: this.onInvalid, capture: true});
			this.addEvent({target: this.el, name: 'change', callback: ({target}) => this.queue(target)});
			this.addEvent({target: this.el, name: 'submit', callback: this.onSubmit});
			this.addEvent({target: this.view, name: 'job:status', callback: this.onStatus});
		}

		/**
		 * A job that ended changed the images, e.g. how many
		 * have their WebP or AVIF version.
		 *
		 * @param {CustomEvent} event
		 */
		onStatus({detail: {busy}}) {
			if (this.busy && !busy) {
				this.refreshLater();
			}
			this.busy = busy;
		}

		/**
		 * Enter in a number saves it, rather than sending
		 * the form.
		 *
		 * @param {SubmitEvent} event
		 */
		onSubmit(event) {
			event.preventDefault();
			if (document.activeElement?.name?.startsWith(Form.PREFIX)) {
				this.queue(document.activeElement);
			}
		}

		/**
		 * Saves a setting once the saves before are done:
		 * with its value then, nothing if it is the one
		 * saved (e.g. ticked then unticked meanwhile).
		 *
		 * @param {HTMLInputElement|HTMLSelectElement} input
		 */
		queue(input) {
			if (this.reverting) {
				return;
			}
			this.saving = this.saving.then(() => this.save(input));
		}

		/**
		 * @param {HTMLInputElement|HTMLSelectElement} input
		 */
		async save(input) {
			const name = input.name?.startsWith(Form.PREFIX) && input.name.slice(Form.PREFIX.length, -1);
			if (!name || input.type === 'hidden' || this.unchanged(input)) {
				return;
			}
			if (!input.checkValidity()) {
				input.reportValidity();
				return;
			}
			const value = Form.value(input);
			let response;
			try {
				response = await this.view.api.post('octopus_settings_save', {name, value});
			} catch {
				response = {success: false};
			}
			// Changed again meanwhile: its next save is waiting, the field keeps what it shows.
			const shown = Form.value(input) === value;
			if (!response.success) {
				if (shown) {
					this.revert(input);
				}
				this.mark(input, false);
				return;
			}
			this.apply(input, response.data.value, shown);
			this.mark(input, !response.data.error, response.data.error ? __('Not applied', 'octopus-image-optimizer') : '');
			this.showError(input, response.data.error);
			this.view.emit('settings:saved', {name, value: response.data.value});
			this.refreshLater();
		}

		/**
		 * Whether the value is the one saved last, e.g. a
		 * number entered then left.
		 *
		 * @param {HTMLInputElement|HTMLSelectElement} input
		 * @returns {boolean}
		 */
		unchanged(input) {
			if (input.type === 'checkbox') {
				return input.checked === input.defaultChecked;
			}
			if (input.type === 'radio') {
				// Another one of its group was picked since: its own save follows.
				return !input.checked || input.defaultChecked;
			}
			if (input.tagName === 'SELECT') {
				return input.selectedOptions[0]?.defaultSelected ?? false;
			}
			return input.value === input.defaultValue;
		}

		/**
		 * The value of a field, as sent to the server.
		 *
		 * @param {HTMLInputElement|HTMLSelectElement} input
		 * @returns {string}
		 */
		static value(input) {
			if (input.type === 'checkbox') {
				return input.checked ? '1' : '0';
			}
			if (input.type === 'radio') {
				return input.checked ? input.value : '';
			}
			return input.value;
		}

		/**
		 * Keeps the value saved as the one to put back,
		 * and shows it, e.g. a number brought within its
		 * bounds, unless the field changed again since.
		 *
		 * @param {HTMLInputElement|HTMLSelectElement} input
		 * @param {boolean|number|string} value
		 * @param {boolean} show
		 */
		apply(input, value, show) {
			value = input.type === 'checkbox' ? Boolean(value) : String(value);
			if (input.type === 'checkbox') {
				// Taken before: the default moves an untouched box with it.
				const shown = input.checked;
				input.defaultChecked = value;
				input.checked = show ? value : shown;
			} else if (input.type === 'radio') {
				// Taken before: a radio checked unchecks the others, the default moves them.
				const shown = this.group(input).find((radio) => radio.checked);
				this.group(input).forEach((radio) => (radio.defaultChecked = radio.value === value));
				this.group(input).forEach((radio) => (radio.checked = show ? radio.value === value : radio === shown));
			} else if (input.tagName === 'SELECT') {
				const shown = input.value;
				[...input.options].forEach((option) => (option.defaultSelected = option.value === value));
				input.value = show ? value : shown;
			} else {
				const shown = input.value;
				input.defaultValue = value;
				input.value = show ? value : shown;
			}
		}

		/**
		 * Puts the value saved last back, and tells the
		 * fields depending on it (DependentField.js).
		 *
		 * @param {HTMLInputElement|HTMLSelectElement} input
		 */
		revert(input) {
			if (input.type === 'checkbox') {
				input.checked = input.defaultChecked;
			} else if (input.type === 'radio') {
				this.group(input).forEach((radio) => (radio.checked = radio.defaultChecked));
			} else if (input.tagName === 'SELECT') {
				[...input.options].forEach((option) => (option.selected = option.defaultSelected));
			} else {
				input.value = input.defaultValue;
			}
			this.reverting = true;
			input.dispatchEvent(new Event('change', {bubbles: true}));
			this.reverting = false;
		}

		/**
		 * @param {HTMLInputElement} radio
		 * @returns {HTMLInputElement[]}
		 */
		group(radio) {
			return [...this.el.querySelectorAll('input[type="radio"]')].filter((input) => input.name === radio.name);
		}

		/**
		 * Marks a setting saved, for a moment, or not saved,
		 * until it is saved: on the left of its control, over
		 * the page, so that nothing moves (fields.css). The
		 * mark before goes at once, the new one comes a
		 * moment after, even on the same setting: each save
		 * blinks.
		 *
		 * @param {HTMLElement} input
		 * @param {boolean} saved
		 * @param {string} [failed] The text of a failure, other than not saved.
		 */
		mark(input, saved, failed = '') {
			const place = input.closest('.octopus-toggle, .octopus-field__control');
			if (!place) {
				return;
			}
			const text = saved ? __('Saved', 'octopus-image-optimizer') : (failed || __('Not saved', 'octopus-image-optimizer'));
			const mark = document.createElement('span');
			mark.className = `octopus-autosave${saved ? '' : ' octopus-autosave--error'}`;
			mark.textContent = text;
			mark.setAttribute('role', 'status');
			if (saved) {
				mark.addEventListener('animationend', () => mark.remove());
			}
			const gap = this.current?.isConnected ? Form.MARK_GAP : 0;
			this.current?.remove();
			clearTimeout(this.markTimer);
			this.current = mark;
			this.markTimer = setTimeout(() => place.append(mark), gap);
			this.view.announce(text);
		}

		/**
		 * Shows the error of what follows a setting under
		 * it, or removes the one shown.
		 *
		 * @param {HTMLElement} input
		 * @param {string} error
		 */
		showError(input, error) {
			const field = input.closest('.octopus-field');
			field?.querySelector('.octopus-field__error')?.remove();
			if (!field || !error) {
				return;
			}
			const notice = document.createElement('p');
			notice.className = 'octopus-field__error';
			notice.textContent = error;
			field.append(notice);
			this.view.announce(error);
		}

		/**
		 * Takes the parts of the page showing the settings
		 * again once the changes in a row are saved, or a
		 * job ended.
		 */
		refreshLater() {
			clearTimeout(this.refreshTimer);
			this.refreshTimer = setTimeout(() => this.refresh(), Form.REFRESH_DELAY);
		}

		async refresh() {
			let page;
			try {
				const response = await fetch(location.href, {credentials: 'same-origin'});
				page = new DOMParser().parseFromString(await response.text(), 'text/html');
			} catch {
				return;
			}
			this.view.el.querySelectorAll('.js-octopus-live').forEach((part) => {
				const fresh = page.getElementById(part.id);
				if (!fresh) {
					return;
				}
				if (part.dataset.live === 'class') {
					part.className = fresh.className;
				} else {
					part.replaceWith(fresh);
				}
			});
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

		destroy() {
			super.destroy();
			clearTimeout(this.refreshTimer);
			clearTimeout(this.markTimer);
		}
	}

	window.octopus.Form = Form;
})();
