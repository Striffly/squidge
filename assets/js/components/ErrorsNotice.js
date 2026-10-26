/**
 * components/ErrorsNotice.js
 *
 * The OIO errors of an image, shown right after it is
 * uploaded to the media library. Built on the template
 * of the notice (.js-octopus-errors-notice-template):
 * the errors go in the notice of the page, or in a new
 * one from the template, each from the template of an
 * error. Dismissing the notice clears the recent
 * errors.
 */

(() => {
	const {Component} = window.octopus;

	class ErrorsNotice extends Component {
		getElems() {
			super.getElems();
			this.errorTemplate = document.querySelector('.js-octopus-error-template');
		}

		events() {
			super.events();
			// The dismiss button is added by WordPress.
			this.addEvent({target: document, name: 'click', callback: this.onClick});
			// The upload response is the attachment data, with the errors added by OIO.
			window.wp?.Uploader?.queue?.on('change:uploading', (attachment) => {
				const errors = attachment.get('octopusErrors');
				if (!attachment.get('uploading') && errors?.length) {
					this.show(errors);
				}
			});
		}

		/**
		 * @param {MouseEvent} event
		 */
		onClick(event) {
			if (event.target.closest('.js-octopus-errors-notice .notice-dismiss')) {
				this.view.api.post(this.view.settings.action);
			}
		}

		/**
		 * Adds errors at the top of the notice, each one
		 * escaped and rendered by PHP, the same as the
		 * errors shown when the page loads.
		 *
		 * @param {string[]} errors
		 */
		show(errors) {
			const list = this.notice().querySelector('.js-octopus-errors-list');
			errors.forEach((html) => {
				const error = this.errorTemplate.content.cloneNode(true);
				error.querySelector('.js-octopus-error').innerHTML = html;
				list.prepend(error);
			});
		}

		/**
		 * The notice of the page, or a new one from the
		 * template, where WordPress puts its notices.
		 *
		 * @returns {HTMLElement}
		 */
		notice() {
			const shown = document.querySelector('.js-octopus-errors-notice');
			if (shown) {
				return shown;
			}
			const notice = this.el.content.firstElementChild.cloneNode(true);
			const headerEnd = document.querySelector('.wp-header-end');
			if (headerEnd) {
				headerEnd.after(notice);
			} else {
				document.querySelector('.wrap')?.prepend(notice);
			}
			// WordPress adds its dismiss button.
			document.dispatchEvent(new Event('wp-notice-added'));
			return notice;
		}
	}

	window.octopus.ErrorsNotice = ErrorsNotice;
})();
