// Writes l10n/<locale>.js from l10n/<locale>.json. Nextcloud reads the .json
// on the server and loads the .js in the browser, so both must hold the same
// strings; the locale test fails when they drift.
//
//   node scripts/l10n-js.mjs
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

export const APP_ID = 'synaplan_integration'
export const L10N_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', 'l10n')

/**
 * The browser bundle of one locale, in the layout Nextcloud's own tooling writes.
 *
 * @param {{translations: Record<string, string>, pluralForm: string}} locale Contents of the .json
 * @return {string}
 */
export function toJs(locale) {
	const lines = Object.entries(locale.translations).map(
		([source, translation]) =>
			`    ${JSON.stringify(source)} : ${JSON.stringify(translation)}`,
	)
	return `OC.L10N.register(\n    ${JSON.stringify(APP_ID)},\n    {\n${lines.join(',\n')}\n},\n${JSON.stringify(locale.pluralForm)});\n`
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	for (const file of readdirSync(L10N_DIR).filter((name) =>
		name.endsWith('.json'),
	)) {
		const locale = JSON.parse(readFileSync(join(L10N_DIR, file), 'utf8'))
		writeFileSync(join(L10N_DIR, file.replace(/\.json$/, '.js')), toJs(locale))
	}
}
