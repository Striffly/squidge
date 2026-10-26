/**
 * settings.js
 *
 * Starts the OIO options page: a component on each
 * element with its js- class. The jobs and the test
 * are on the page only when the server can run them.
 */

(() => {
	const {
		Screen, Tabs, Form, HelpToggle, DependentField, CopyButton, Wallet,
		Count, Job, JobStart, Savings, Cta, AllOptimised, DeliveryTest,
	} = window.octopus;

	const componentList = [
		{name: 'js-octopus-tabs', Class: Tabs},
		{name: 'js-octopus-form', Class: Form},
		{name: 'js-octopus-help-toggle', Class: HelpToggle},
		{name: 'js-octopus-dependent', Class: DependentField},
		{name: 'js-octopus-copy', Class: CopyButton},
		{name: 'js-octopus-wallet', Class: Wallet},
		{name: 'js-octopus-count', Class: Count},
		{name: 'js-octopus-job', Class: Job},
		{name: 'js-octopus-job-start', Class: JobStart},
		{name: 'js-octopus-savings', Class: Savings},
		{name: 'js-octopus-cta', Class: Cta},
		{name: 'js-octopus-all-optimised', Class: AllOptimised},
		{name: 'js-octopus-delivery-test', Class: DeliveryTest},
	];

	const page = document.querySelector('.js-octopus-page');
	if (page) {
		// Without JS, the panels show one after another, without tabs. The page sets it before it paints, after its tabs.
		page.classList.add('octopus-page--js');
		new Screen(page, componentList, window.octopusSettings);
		// What waits for its component shows from now on, e.g. the Bitcoin address.
		page.classList.add('octopus-page--ready');
	}
})();
