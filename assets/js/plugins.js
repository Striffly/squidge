/**
 * plugins.js
 *
 * Starts the warning of the plugins screen, before
 * OIO is deactivated.
 */

(() => {
	const {Screen, DeactivationWarning} = window.octopus;

	const componentList = [
		{name: 'js-octopus-deactivation', Class: DeactivationWarning},
	];

	new Screen(document.body, componentList, window.octopusPlugins);
})();
