import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

import { L10N_DIR, toJs } from '../../../scripts/l10n-js.mjs'

/** The locales the app ships, as in the plan: Nextcloud's informal de, a formal de_DE, es, fr, tr. */
const LOCALES = ['de', 'de_DE', 'es', 'fr', 'tr']

/** The core's words (plan 00 §11): each locale says them the same way the core does. */
const CORE_WORDS: Record<string, Record<string, string>> = {
	'Make searchable by AI': {
		de: 'Für den Assistenten durchsuchbar machen',
		de_DE: 'Für den Assistenten durchsuchbar machen',
		es: 'Hacer consultable por IA',
		fr: 'Rendre recherchable par l’IA',
		tr: 'Yapay zekâ ile aranabilir yap',
	},
	'Knowledge folder': {
		de: 'Wissensordner',
		de_DE: 'Wissensordner',
		es: 'Carpeta de conocimiento',
		fr: 'Dossier de connaissances',
		tr: 'Bilgi klasörü',
	},
	'Connect your Synaplan account': {
		de: 'Synaplan-Konto verbinden',
		de_DE: 'Synaplan-Konto verbinden',
		es: 'Conectar tu cuenta de Synaplan',
		fr: 'Connecter votre compte Synaplan',
		tr: 'Synaplan hesabını bağla',
	},
}

/** "Searchable by AI" is a state inside sentences; each locale uses the core's wording for it. */
const SEARCHABLE: Record<string, string> = {
	de: 'KI-durchsuchbar',
	de_DE: 'KI-durchsuchbar',
	es: 'consultable por IA',
	fr: 'recherchable par l’IA',
	tr: 'yapay zekâ ile aranabilir',
}

/** Words the plan bans from primary copy (00 §11), with the forms translations would use. */
const BANNED = [
	/\bRAG\b/,
	/vector|vektor/i,
	/embedding/i,
	/chunk/i,
	/index|indiz/i,
	/token/i,
	/\bOIDC\b/,
	/webhook/i,
	/PROPFIND/i,
	/\bNATS\b/,
	/\bCS3\b/,
	/\breva\b/i,
	/\betag\b/i,
	/source_id/i,
	/instance secret/i,
	/\bcron\b/i,
	/provision/i,
	/selector/i,
	/pickup/i,
	/ticket/i,
	/\bpath\b/i,
	/Qdrant/i,
]

type Locale = { translations: Record<string, string>; pluralForm: string }

const read = (file: string): string => readFileSync(join(L10N_DIR, file), 'utf8')
const locale = (code: string): Locale => JSON.parse(read(`${code}.json`))
const placeholders = (text: string): string[] =>
	(text.match(/\{[a-zA-Z]+\}/g) ?? []).sort()
const withoutPlaceholders = (text: string): string =>
	text.replace(/\{[a-zA-Z]+\}/g, '')

/**
 * Every string the app passes to t() or n(), with JavaScript escapes resolved
 * the way the browser sees them.
 *
 * @param dir Directory to scan
 */
function sourceStrings(dir: string): Set<string> {
	const call =
		/\b[tn]\(\s*['"]synaplan_integration['"]\s*,\s*(['"])((?:\\.|(?!\1).)*)\1/gs
	const strings = new Set<string>()
	for (const name of readdirSync(dir)) {
		const path = join(dir, name)
		if (statSync(path).isDirectory()) {
			sourceStrings(path).forEach((text) => strings.add(text))
		} else if (/\.(vue|ts)$/.test(name)) {
			for (const match of readFileSync(path, 'utf8').matchAll(call)) {
				strings.add(
					match[2]
						.replace(/\\u([0-9a-fA-F]{4})/g, (_, hex) =>
							String.fromCharCode(parseInt(hex, 16)),
						)
						.replace(/\\(['"\\])/g, '$1'),
				)
			}
		}
	}
	return strings
}

const SOURCE = sourceStrings(join(L10N_DIR, '..', 'src'))

describe('source strings', () => {
	it('are found', () => {
		expect(SOURCE.size).toBeGreaterThan(200)
	})

	it.each([...SOURCE])('"%s" uses no banned word', (text) => {
		for (const word of BANNED) {
			expect(withoutPlaceholders(text)).not.toMatch(word)
		}
	})
})

describe.each(LOCALES)('%s', (code) => {
	const { translations } = locale(code)

	it('translates every string of the app and nothing else', () => {
		expect(Object.keys(translations).sort()).toEqual([...SOURCE].sort())
	})

	it('keeps every placeholder and leading space', () => {
		for (const [source, translation] of Object.entries(translations)) {
			expect(placeholders(translation), source).toEqual(placeholders(source))
			expect(translation.startsWith(' '), source).toBe(source.startsWith(' '))
		}
	})

	it("uses the core's words", () => {
		for (const [source, words] of Object.entries(CORE_WORDS)) {
			expect(translations[source]).toBe(words[code])
		}
		expect(Object.values(translations).join('\n').toLowerCase()).toContain(
			SEARCHABLE[code].toLowerCase(),
		)
	})

	it('uses no banned word', () => {
		for (const translation of Object.values(translations)) {
			for (const word of BANNED) {
				expect(withoutPlaceholders(translation)).not.toMatch(word)
			}
		}
	})

	it('has a browser bundle generated from it', () => {
		expect(read(`${code}.js`)).toBe(toJs(locale(code)))
	})
})
