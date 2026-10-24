import { describe, expect, it } from 'vitest'

import { describeFailure, failureFromError } from '@/failures'

/**
 * A rejected request as axios reports it; isAxiosError() checks the flag.
 *
 * @param status HTTP status of the app's answer
 * @param data Body of the app's answer
 */
function rejected(status: number, data: unknown) {
	return {
		isAxiosError: true,
		message: 'Request failed',
		response: { status, data },
	}
}

describe('describeFailure', () => {
	it('asks to connect and links to the Synaplan settings', () => {
		const failure = describeFailure('not_connected')

		expect(failure.text).toBe('Connect your Synaplan account first.')
		expect(failure.settingsUrl).toMatch(
			/\/settings\/user\/synaplan_integration$/,
		)
	})

	it('names an unreachable Synaplan and the way out', () => {
		expect(describeFailure('synaplan_unreachable')).toEqual({
			text: 'Synaplan cannot be reached. Try again in a minute, or ask your administrator.',
		})
	})

	it.each([
		'consent_required',
		'account_unavailable',
		'insufficient_scope',
		'synaplan_error',
		'synaplan_key_rejected',
		'rate_limited',
		'file_not_found',
		'file_too_large',
		'unsupported_type',
		'file_empty',
		'no_text',
		'translation_unavailable',
	])('gives %s a sentence of its own', (code) => {
		const { text } = describeFailure(code)

		expect(text).toMatch(/\.$/)
		expect(text).not.toContain(code)
		expect(text).not.toBe(describeFailure().text)
	})

	it("shows Synaplan's own sentence for a code the app does not know", () => {
		expect(
			describeFailure('synaplan_rejected', 'Try a shorter document.'),
		).toEqual({
			text: 'Try a shorter document.',
		})
	})

	it('falls back to one general sentence, never a code', () => {
		expect(describeFailure('unexpected').text).toBe(
			'Something went wrong. Try again, or ask your administrator.',
		)
		expect(describeFailure().text).toBe(describeFailure('unexpected').text)
	})
})

describe('failureFromError', () => {
	it("reads the app's code and Synaplan's sentence from the answer", () => {
		expect(
			failureFromError(
				rejected(409, { success: false, error: 'not_connected' }),
			).settingsUrl,
		).toBeDefined()
		expect(
			failureFromError(
				rejected(422, {
					success: false,
					error: 'synaplan_rejected',
					message: 'Storage limit reached.',
				}),
			),
		).toEqual({ text: 'Storage limit reached.' })
	})

	it('keeps anything else general', () => {
		expect(failureFromError(new Error('Network Error')).text).toBe(
			describeFailure().text,
		)
		expect(
			failureFromError(rejected(500, '<html>Internal Server Error</html>'))
				.text,
		).toBe(describeFailure().text)
	})
})
