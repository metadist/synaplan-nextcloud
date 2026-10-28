import { describe, expect, it, vi } from 'vitest'
import {
	badgeForState,
	glyphMarkup,
	labelForState,
} from '../../../src/cloud_state/glyphs'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars?: Record<string, string>) => {
		if (!vars) return text
		return Object.entries(vars).reduce(
			(acc, [k, v]) => acc.replace(`{${k}}`, v),
			text,
		)
	},
}))

describe('cloud glyphs', () => {
	it('maps file and watch states to badges', () => {
		expect(badgeForState('searchable')).toBeNull()
		expect(badgeForState('adding')).toBe('clock')
		expect(badgeForState('updating')).toBe('arrows')
		expect(badgeForState('changed')).toBe('dot')
		expect(badgeForState('failed')).toBe('alert')
		expect(badgeForState('searchable', 'paused')).toBe('pause')
		expect(badgeForState('searchable', 'attention')).toBe('alert')
	})

	it('builds tooltip labels per state', () => {
		expect(labelForState('searchable', 'Nextcloud · Specs')).toBe(
			'Searchable by AI — in “Nextcloud · Specs”',
		)
		expect(labelForState('updating')).toBe('Changed — Synaplan is updating it')
		expect(labelForState('none')).toBe('')
	})

	it('renders the bird, eyes, badges, and white-label overrides', () => {
		expect(glyphMarkup({ eyes: false, badge: null, size: 16 })).toContain('<svg')
		expect(glyphMarkup({ eyes: true, badge: 'arrows', size: 20 })).toContain(
			'cx="15.5"',
		)
		expect(
			glyphMarkup({
				eyes: false,
				overrides: { bird: 'https://example.org/bird.svg' },
			}),
		).toContain('https://example.org/bird.svg')
	})
})
