/**
 * components/DeliveryTest.js
 *
 * The button testing the delivery
 * (.js-octopus-delivery-test): asks the server to
 * request an optimised image as a browser, then shows
 * the verdict next to the button and lists the checks,
 * each from the .js-octopus-check-template. Their
 * advice comes from the server, as HTML.
 */

(() => {
	const {__, _n, sprintf} = wp.i18n;
	const {Component} = window.octopus;

	class DeliveryTest extends Component {
		getElems() {
			super.getElems();
			this.summary = this.view.el.querySelector('.js-octopus-test-summary');
			this.result = this.view.el.querySelector('.js-octopus-test-result');
			this.sample = this.view.el.querySelector('.js-octopus-test-sample');
			this.checks = this.view.el.querySelector('.js-octopus-test-checks');
			this.template = this.view.el.querySelector('.js-octopus-check-template');
		}

		events() {
			super.events();
			this.addEvent({target: this.el, name: 'click', callback: this.run});
		}

		async run() {
			this.el.disabled = true;
			this.el.textContent = __('Testing…', 'octopus-image-optimizer');
			try {
				const response = await this.view.api.post('octopus_delivery_test');
				this.checks.replaceChildren();
				this.result.hidden = false;
				if (response.success) {
					this.showChecks(response.data);
				} else {
					this.sample.textContent = response.data?.message ?? __('The test failed.', 'octopus-image-optimizer');
					this.showSummary(this.sample.textContent, 'fail');
				}
			} finally {
				this.el.disabled = false;
				this.el.textContent = __('Run the test again', 'octopus-image-optimizer');
			}
		}

		/**
		 * @param {{sample: string, checks: Array<{state: string, label: string, detail: string, advice: string}>}} data
		 */
		showChecks({sample, checks}) {
			const failed = checks.filter((check) => check.state === 'fail').length;
			const total = checks.length;
			if (failed) {
				/* translators: 1: the number of checks failed, 2: the number of checks. */
				this.showSummary(sprintf(_n('%1$s of %2$s check failed: see the results below.', '%1$s of %2$s checks failed: see the results below.', total, 'octopus-image-optimizer'), failed, total), 'fail');
			} else {
				/* translators: %s: the number of checks. */
				this.showSummary(sprintf(_n('The %s check passed.', 'All %s checks passed.', total, 'octopus-image-optimizer'), total), 'ok');
			}
			/* translators: %s: the file of an image. */
			this.sample.textContent = sprintf(__('Tested on %s.', 'octopus-image-optimizer'), sample);
			this.checks.append(...checks.map((check) => this.renderCheck(check)));
		}

		/**
		 * @param {{state: string, label: string, detail: string, advice: string}} check
		 * @returns {DocumentFragment}
		 */
		renderCheck({state, label, detail, advice}) {
			const check = this.template.content.cloneNode(true);
			check.querySelector('.js-octopus-check').classList.add(`octopus-checks__item--${state}`);
			check.querySelector('.js-octopus-check-label').textContent = label;
			check.querySelector('.js-octopus-check-detail').textContent = detail;
			// Escaped by the server.
			check.querySelector('.js-octopus-check-advice').innerHTML = advice;
			return check;
		}

		/**
		 * The verdict, next to the button: the results may
		 * be below the fold.
		 *
		 * @param {string} text
		 * @param {'ok'|'fail'} state
		 */
		showSummary(text, state) {
			this.summary.textContent = text;
			this.summary.classList.toggle('octopus-test__summary--ok', state === 'ok');
			this.summary.classList.toggle('octopus-test__summary--fail', state === 'fail');
			this.summary.hidden = false;
		}
	}

	window.octopus.DeliveryTest = DeliveryTest;
})();
