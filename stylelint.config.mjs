/**
 * stylelint.config.mjs
 *
 * The checks of the stylesheets: the standard config,
 * with the BEM classes of OIO and the blank lines left
 * to the author. Run on the staged stylesheets by
 * lefthook, or with bun run lint:css.
 */

/** @type {import('stylelint').Config} */
export default {
	extends: ['stylelint-config-standard'],
	rules: {
		'selector-class-pattern': [
			'^[a-z][a-z0-9]*(-[a-z0-9]+)*(__[a-z0-9]+(-[a-z0-9]+)*)?(--[a-z0-9]+(-[a-z0-9]+)*)?$',
			{
				message: 'Expected class selector to be BEM: block__element--modifier, in kebab-case',
				resolveNestedSelectors: true,
			},
		],

		// The states (&:hover, &[open]) follow their element, whatever their specificity.
		'no-descending-specificity': null,

		// Dashicons is an icon font: no other font has its glyphs.
		'font-family-no-missing-generic-family-keyword': [true, {ignoreFontFamilies: ['dashicons']}],

		// The font tokens keep the names of the fonts as they are written.
		'value-keyword-case': ['lower', {ignoreProperties: ['/^--octopus-font-/']}],

		// Without the prefix, the gradient text of older browsers turns transparent.
		'property-no-vendor-prefix': [true, {ignoreProperties: ['/background-clip$/']}],

		'comment-empty-line-before': null,
		'declaration-empty-line-before': null,
		'custom-property-empty-line-before': null,
	},
};
