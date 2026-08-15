import eslint from '@eslint/js';
import { defineConfig } from 'eslint/config';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default defineConfig([
	{
		ignores: [ 'assets/**', 'node_modules/**', 'release/**', 'wp/**' ],
	},
	{
		files: [ '**/*.mjs' ],
		extends: [ eslint.configs.recommended ],
		languageOptions: {
			globals: globals.node,
		},
	},
	{
		files: [ '**/*.ts' ],
		extends: [
			eslint.configs.recommended,
			...tseslint.configs.recommendedTypeChecked,
		],
		languageOptions: {
			globals: {
				...globals.browser,
				jQuery: 'readonly',
				$: 'readonly',
			},
			parserOptions: {
				projectService: true,
				tsconfigRootDir: import.meta.dirname,
			},
		},
		rules: {
			'@typescript-eslint/no-unused-vars': [
				'error',
				{
					argsIgnorePattern: '^_',
					caughtErrorsIgnorePattern: '^_',
					varsIgnorePattern: '^_',
				},
			],
		},
	},
]);
