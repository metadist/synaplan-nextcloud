import { readdirSync, readFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { describe, expect, it } from 'vitest'

const SRC = join(process.cwd(), 'src')

/**
 * Nextcloud 33 to 35 make --color-error the fill of an error card: pale pink
 * in the light theme, nearly the page color in the dark one. Red text takes
 * --color-error-text.
 */
const ERROR_FILL_AS_TEXT = /(?<![\w-])color:\s*var\(--color-error\s*[,)]/

/**
 * The app's styles and the scripts that inject some.
 *
 * @param dir Folder to search
 */
function sources(dir: string): string[] {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) {
			return sources(path)
		}
		return /\.(vue|ts|css)$/.test(entry.name) ? [path] : []
	})
}

describe('red text', () => {
	it('takes the error text color, not the fill of an error card', () => {
		const found = sources(SRC).flatMap((file) =>
			readFileSync(file, 'utf8')
				.split('\n')
				.flatMap((line, index) =>
					ERROR_FILL_AS_TEXT.test(line)
						? [`${relative(SRC, file)}:${index + 1}`]
						: [],
				),
		)
		expect(found).toEqual([])
	})

	it('finds the fill of an error card used as a text color', () => {
		expect(
			ERROR_FILL_AS_TEXT.test('\tcolor: var(--color-error, #d93025);'),
		).toBe(true)
		expect(ERROR_FILL_AS_TEXT.test('color:var(--color-error)')).toBe(true)
		expect(
			ERROR_FILL_AS_TEXT.test('\tcolor: var(--color-error-text, #db1d1d);'),
		).toBe(false)
		expect(
			ERROR_FILL_AS_TEXT.test('\tbackground-color: var(--color-error);'),
		).toBe(false)
		expect(ERROR_FILL_AS_TEXT.test('\t--sp-color: var(--color-error);')).toBe(
			false,
		)
	})
})
