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

/**
 * The read choices speak as the person (plan 05, folder-read copy), so formal
 * and informal German say them the same way.
 */
const READ_CHOICES: Record<string, string> = {
	'Only what I watch': 'Nur was ich beobachte',
	'Also folders I allow': 'Auch Ordner, die ich freigebe',
	'All my files': 'Alle meine Dateien',
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

type Translation = string | string[]
type Locale = { translations: Record<string, Translation>; pluralForm: string }

const read = (file: string): string => readFileSync(join(L10N_DIR, file), 'utf8')
const locale = (code: string): Locale => JSON.parse(read(`${code}.json`))
function forms(translation: Translation): string[] {
	return Array.isArray(translation) ? translation : [translation]
}
function sourceForms(source: string): string[] {
	return isPlural(source) ? source.slice(1, -1).split('_::_') : [source]
}
function isPlural(source: string): boolean {
	return source.startsWith('_') && source.includes('_::_')
}
function placeholders(text: string): string[] {
	return (text.match(/\{[a-zA-Z]+\}/g) ?? []).sort()
}
function withoutPlaceholders(text: string): string {
	return text.replace(/\{[a-zA-Z]+\}/g, '')
}
function unescape(text: string): string {
	return text
		.replace(/\\u([0-9a-fA-F]{4})/g, (_, hex) =>
			String.fromCharCode(parseInt(hex, 16)),
		)
		.replace(/\\(['"\\])/g, '$1')
}

const QUOTED = '([\'"])((?:\\\\.|(?!\\1).)*)\\1'

/**
 * Every string the app passes to t() or n(), with JavaScript escapes resolved
 * the way the browser sees them. n() is keyed "_singular_::_plural_", as
 * Nextcloud looks it up.
 *
 * @param dir Directory to scan
 */
function sourceStrings(dir: string): Set<string> {
	const single = new RegExp(
		`\\bt\\(\\s*['"]synaplan_integration['"]\\s*,\\s*${QUOTED}`,
		'gs',
	)
	const plural = new RegExp(
		`\\bn\\(\\s*['"]synaplan_integration['"]\\s*,\\s*${QUOTED}\\s*,\\s*${QUOTED.replaceAll('\\1', '\\3')}`,
		'gs',
	)
	const strings = new Set<string>()
	for (const name of readdirSync(dir)) {
		const path = join(dir, name)
		if (statSync(path).isDirectory()) {
			sourceStrings(path).forEach((text) => strings.add(text))
		} else if (/\.(vue|ts)$/.test(name)) {
			const code = readFileSync(path, 'utf8')
			for (const match of code.matchAll(single)) {
				strings.add(unescape(match[2]))
			}
			for (const match of code.matchAll(plural)) {
				strings.add(`_${unescape(match[2])}_::_${unescape(match[4])}_`)
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

describe.each(['de', 'de_DE'])('%s', (code) => {
	it('says the read choices in the first person', () => {
		const { translations } = locale(code)
		for (const [source, german] of Object.entries(READ_CHOICES)) {
			expect(translations[source], source).toBe(german)
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
			const english = sourceForms(source)
			forms(translation).forEach((form, i) => {
				const original = english[Math.min(i, english.length - 1)]
				expect(placeholders(form), source).toEqual(placeholders(original))
				expect(form.startsWith(' '), source).toBe(original.startsWith(' '))
			})
		}
	})

	it('gives a plural every form of the language', () => {
		const count = Number(/nplurals=(\d+)/.exec(locale(code).pluralForm)?.[1])
		for (const [source, translation] of Object.entries(translations)) {
			const plural = isPlural(source)
			expect(Array.isArray(translation), source).toBe(plural)
			if (plural) {
				expect(translation, source).toHaveLength(count)
				for (const form of forms(translation)) {
					expect(form, source).toContain('%n')
				}
			}
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
		for (const form of Object.values(translations).flatMap(forms)) {
			for (const word of BANNED) {
				expect(withoutPlaceholders(form)).not.toMatch(word)
			}
		}
	})

	it('has a browser bundle generated from it', () => {
		expect(read(`${code}.js`)).toBe(toJs(locale(code)))
	})
})
