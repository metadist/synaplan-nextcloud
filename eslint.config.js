import { recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,
	{
		rules: {
			// Prettier owns wrapping and indent, including t() argument lists
			// in <script setup> and the HTML layout of Vue templates.
			'@stylistic/indent': 'off',
			'@stylistic/indent-binary-ops': 'off',
			'@stylistic/operator-linebreak': 'off',
			'@stylistic/function-paren-newline': 'off',
			'@stylistic/implicit-arrow-linebreak': 'off',
			'@stylistic/exp-list-style': 'off',
			'@stylistic/member-delimiter-style': 'off',
			'vue/first-attribute-linebreak': 'off',
			'vue/html-closing-bracket-newline': 'off',
			'vue/html-indent': 'off',
			// Relative TypeScript imports stay extensionless. vue-tsc rejects a
			// .ts suffix unless allowImportingTsExtensions is on, and Vite
			// resolves the suffix itself.
			'import-extensions/extensions': 'off',
		},
	},
	{
		files: ['**/*.vue'],
		rules: {
			// Prettier writes void elements as <img />.
			'vue/html-self-closing': ['warn', { html: { void: 'always' } }],
		},
	},
]
