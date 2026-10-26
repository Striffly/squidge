/**
 * eslint.config.mjs
 *
 * The checks of the scripts: the mistakes eslint
 * recommends to catch, not their formatting. Run on
 * the staged scripts by lefthook, or with bun run
 * lint:js.
 */

import js from '@eslint/js';
import globals from 'globals';

/** @type {import('eslint').Linter.Config[]} */
export default [
	{
		ignores: ['node_modules/**', 'vendor/**', 'vendor-prefixed/**', 'octopus-image-optimizer/**'],
	},

	js.configs.recommended,

	// The tools run under Bun.
	{
		files: ['*.{js,mjs}', 'tools/**/*.js'],
		languageOptions: {
			sourceType: 'module',
			globals: {
				...globals.node,
				Bun: 'readonly',
			},
		},
	},

	// The admin scripts load as classic scripts, one after another, sharing window.octopus.
	{
		files: ['assets/js/**/*.js'],
		languageOptions: {
			sourceType: 'script',
			globals: {
				...globals.browser,
				wp: 'readonly',
				octopusSettings: 'readonly',
				octopusPlugins: 'readonly',
				octopusNotices: 'readonly',
				octopusErrors: 'readonly',
			},
		},
		rules: {
			'no-var': 'error',
			'prefer-const': 'error',
			'object-shorthand': 'error',
			'no-unused-vars': ['warn', {args: 'none', ignoreRestSiblings: true, caughtErrors: 'none'}],
		},
	},
];
