/**
 * media.js
 *
 * Starts the actions of OIO in the media library, list
 * mode: on its body, marked by OIO (MediaColumn).
 */

(() => {
	const {Screen, MediaQueue} = window.octopus;

	const componentList = [
		{name: 'js-octopus-media', Class: MediaQueue},
	];

	document.addEventListener('DOMContentLoaded', () => {
		new Screen(document.documentElement, componentList, window.octopusMedia);
	});
})();
