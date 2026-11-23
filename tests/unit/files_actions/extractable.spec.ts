import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { EXTRACTABLE_MIME_TYPES, isExtractable } from '@/files_actions/extractable'

/**
 * The entries of a string-list constant in ApiController.php.
 *
 * @param source The PHP source
 * @param name The constant's name
 */
function phpList(source: string, name: string): string[] {
	const body = source.match(new RegExp(`const ${name} = \\[([\\s\\S]*?)\\];`))?.[1]
	expect(body, `${name} in ApiController.php`).toBeDefined()
	return [...(body ?? '').matchAll(/'([^']+)'/g)].map((m) => m[1])
}

describe('extractable types', () => {
	it('are the ones ApiController reads text from', () => {
		const source = readFileSync(
			join(process.cwd(), 'lib/Controller/ApiController.php'),
			'utf8',
		)

		expect([...EXTRACTABLE_MIME_TYPES].sort()).toEqual(
			[
				...phpList(source, 'TEXT_MIME_TYPES'),
				...phpList(source, 'BINARY_MIME_TYPES'),
			].sort(),
		)
	})

	it.each([
		'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
		'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
		'application/vnd.oasis.opendocument.graphics',
		'text/calendar',
		'text/vcard',
		'image/png',
	])('leave out %s, which the extractor refuses', (mime) => {
		expect(isExtractable(mime)).toBe(false)
	})

	it('match by prefix, as the extractor does', () => {
		expect(isExtractable('text/plain; charset=utf-8')).toBe(true)
		expect(isExtractable('application/vnd.ms-excel')).toBe(true)
	})
})
