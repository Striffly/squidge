/**
 * utils/Api.js
 *
 * The AJAX actions of OIO, posted with their nonce.
 */

(() => {
	class Api {
		/**
		 * @param {{ajaxurl: string, nonce: string}} settings
		 */
		constructor({ajaxurl, nonce}) {
			this.url = ajaxurl;
			this.nonce = nonce;
		}

		/**
		 * Posts an action, and returns its JSON response: a
		 * failure when the site did not answer it, e.g. PHP
		 * restarting or the network down.
		 *
		 * @param {string} action
		 * @param {Object<string, string>} [data]
		 * @returns {Promise<{success: boolean, data: *}>}
		 */
		async post(action, data = {}) {
			const body = new URLSearchParams({action, _ajax_nonce: this.nonce, ...data});
			try {
				const response = await fetch(this.url, {method: 'POST', credentials: 'same-origin', body});
				return await response.json();
			} catch {
				return {success: false, data: null};
			}
		}
	}

	(window.octopus ??= {}).Api = Api;
})();
