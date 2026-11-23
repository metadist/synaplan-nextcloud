import { describe, expect, it } from 'vitest'
import { controlsFor, policyStopped } from '../../src/watchControls'

describe('the owner controls of a watch', () => {
	it('offers Pause and Stop while it watches', () => {
		expect(controlsFor('active')).toEqual({
			pause: true,
			resume: false,
			stop: true,
		})
	})

	it('offers Resume and Stop once paused', () => {
		expect(controlsFor('paused')).toEqual({
			pause: false,
			resume: true,
			stop: true,
		})
	})

	it('offers only Stop when Synaplan stopped it for a reason', () => {
		expect(controlsFor('attention')).toEqual({
			pause: false,
			resume: false,
			stop: true,
		})
	})

	it('offers only Stop while the administrator turned off watching shared folders', () => {
		expect(controlsFor('active', 'shared')).toEqual({
			pause: false,
			resume: false,
			stop: true,
		})
		expect(controlsFor('paused', 'shared')).toEqual({
			pause: false,
			resume: false,
			stop: true,
		})
	})

	it('offers nothing for a stopped watch', () => {
		expect(controlsFor('stopped')).toEqual({
			pause: false,
			resume: false,
			stop: false,
		})
	})

	it('offers only Stop while the administrator turned off watching folders', () => {
		expect(controlsFor('active', 'watches')).toEqual({
			pause: false,
			resume: false,
			stop: true,
		})
	})

	it('names the setting that stopped the watch', () => {
		expect(policyStopped('watches')).toBe(
			'Stopped — your administrator turned off watching folders',
		)
		expect(policyStopped('shared')).toBe(
			'Stopped — your administrator turned off watching shared folders',
		)
	})
})
