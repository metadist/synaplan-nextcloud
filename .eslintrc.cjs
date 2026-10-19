module.exports = {
	extends: ['@nextcloud'],
	rules: {
		'vue/first-attribute-linebreak': 'off',
		'vue/html-closing-bracket-newline': 'off',
		'vue/html-indent': 'off',
		'operator-linebreak': 'off',
		// Prettier owns wrapping/indent of t() argument lists in <script setup>.
		indent: 'off',
	},
	overrides: [
		{
			files: ['*.vue'],
			parser: 'vue-eslint-parser',
			parserOptions: {
				parser: '@typescript-eslint/parser',
			},
			// Components import TypeScript modules without the extension, as Vite
			// resolves them; vue-tsc reports a missing module, as for .ts files.
			settings: {
				'import/resolver': { node: { extensions: ['.js', '.ts', '.vue'] } },
			},
			rules: {
				'n/no-missing-import': 'off',
				'import/extensions': ['warn', 'ignorePackages', { ts: 'never' }],
			},
		},
		{
			files: ['*.ts', '*.tsx'],
			parser: '@typescript-eslint/parser',
		},
	],
}
