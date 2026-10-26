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
		 * Posts an action, and returns its JSON response.
		 *
		 * @param {string} action
		 * @param {Object<string, string>} [data]
		 * @returns {Promise<{success: boolean, data: *}>}
		 */
		async post(action, data = {}) {
			const body = new URLSearchParams({action, _ajax_nonce: this.nonce, ...data});
			const response = await fetch(this.url, {method: 'POST', credentials: 'same-origin', body});
			return response.json();
		}
	}

	(window.octopus ??= {}).Api = Api;
})();
