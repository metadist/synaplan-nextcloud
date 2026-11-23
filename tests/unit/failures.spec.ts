import { describe, expect, it, vi } from 'vitest'
import {
	answerOr,
	describeFailure,
	failureFromAnswer,
	failureFromError,
	failureOr,
	onConsentStale,
} from '@/failures'

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
		'api_plan_required',
		'email_already_used',
		'admin_key_missing',
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
		'watch_needs_edit',
		'watches_off',
		'watch_shared_off',
		'feature_not_configured',
		'platform_disconnected',
		'platform_ambiguous',
		'watch_cap',
		'storage_quota',
		'invalid_watch',
		'model_not_found',
		'no_access',
	])('gives %s a sentence of its own', (code) => {
		const { text } = describeFailure(code)

		expect(text).toMatch(/\.$/)
		expect(text).not.toContain(code)
		expect(text).not.toBe(describeFailure().text)
	})

	it("adds Synaplan's reason after the sentence for a known failure", () => {
		expect(
			describeFailure(
				'account_unavailable',
				'The account is waiting for an administrator.',
			).text,
		).toBe(
			'Synaplan could not set up your account. Ask your administrator. The account is waiting for an administrator.',
		)
		expect(describeFailure('api_plan_required').text).toContain('paid plan')
		expect(describeFailure('email_already_used').text).toContain('email address')
	})

	it('names a turned-off cloud files module, and a missing right', () => {
		expect(
			describeFailure('feature_not_configured', undefined, {
				module: 'cloud_files',
			}).text,
		).toBe(
			'Watching folders and files is turned off on this Synaplan. Ask your administrator to turn it on.',
		)
		expect(
			describeFailure('insufficient_scope', undefined, {
				required: ['cloud:watch'],
			}),
		).toEqual({
			text: 'Your Synaplan connection does not allow watching folders and files. Connect your Synaplan account again.',
			settingsUrl: expect.stringMatching(
				/\/settings\/user\/synaplan_integration$/,
			),
		})
		expect(describeFailure('no_access').text).toBe(
			'You do not have access to this folder or file.',
		)
		expect(describeFailure('watch_needs_edit').text).toContain(
			'permission to edit',
		)
	})

	it('says when Synaplan is turned off for this Nextcloud, and adds a limit', () => {
		expect(
			describeFailure(
				'platform_disconnected',
				'Synaplan is turned off for this cloud.',
			).text,
		).toBe('Synaplan is turned off for this Nextcloud. Ask your administrator.')
		expect(
			describeFailure(
				'watch_cap',
				'You can watch 20 folders. Stop one before watching another.',
			).text,
		).toBe(
			'You have reached the limit for watching folders and files. You can watch 20 folders. Stop one before watching another.',
		)
	})

	it('shows a sentence that arrived in the code field', () => {
		expect(describeFailure('Choose a folder or a file.').text).toBe(
			'Choose a folder or a file.',
		)
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
	it('reads the module and the missing right from the answer', () => {
		expect(
			failureFromAnswer({
				success: false,
				error: 'feature_not_configured',
				module: 'cloud_files',
				docs: 'modules/cloud-files',
			}).text,
		).toContain('Watching folders and files is turned off')
		expect(
			failureFromError(
				rejected(403, {
					error: 'insufficient_scope',
					required: ['files'],
					granted: ['chat'],
				}),
			).text,
		).toContain('using files')
		expect(
			failureOr(
				rejected(500, '<html>Internal Server Error</html>'),
				'Could not save that folder.',
			),
		).toBe('Could not save that folder.')
		expect(answerOr({ error: 'no_access' }, 'Could not save that folder.')).toBe(
			'You do not have access to this folder or file.',
		)
	})

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

describe('onConsentStale', () => {
	it.each(['not_connected', 'consent_required'])(
		'tells an open consent gate when an answer says %s',
		(code) => {
			const listener = vi.fn()
			const stop = onConsentStale(listener)

			failureFromError(rejected(403, { error: code }))
			expect(listener).toHaveBeenCalledTimes(1)

			stop()
			describeFailure(code)
			expect(listener).toHaveBeenCalledTimes(1)
		},
	)

	it('stays quiet for other failures', () => {
		const listener = vi.fn()
		const stop = onConsentStale(listener)

		describeFailure('synaplan_unreachable')
		describeFailure('insufficient_scope')
		describeFailure()
		expect(listener).not.toHaveBeenCalled()
		stop()
	})
})
