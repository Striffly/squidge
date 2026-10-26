/**
 * notices.js
 *
 * Starts the error notice of OIO on the screens of the
 * media library, once the uploader of WordPress is
 * ready with the page.
 */

(() => {
	const {Screen, ErrorsNotice} = window.octopus;

	const componentList = [
		{name: 'js-octopus-errors-notice-template', Class: ErrorsNotice},
	];

	document.addEventListener('DOMContentLoaded', () => {
		new Screen(document.body, componentList, window.octopusNotices);
	});
})();
