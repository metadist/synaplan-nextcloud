import { describe, expect, it } from 'vitest'
import { controlsFor } from '../../src/watchControls'

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
		expect(controlsFor('active', true)).toEqual({
			pause: false,
			resume: false,
			stop: true,
		})
		expect(controlsFor('paused', true)).toEqual({
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
})
