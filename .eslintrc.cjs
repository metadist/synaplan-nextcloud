module.exports = {
	extends: [
		'@nextcloud',
	],
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
		},
		{
			files: ['*.ts', '*.tsx'],
			parser: '@typescript-eslint/parser',
		},
	],
}
