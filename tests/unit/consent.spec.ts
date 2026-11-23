import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { grantConsent } from '@/consent'
import { describeFailure } from '@/failures'

vi.mock('@nextcloud/axios', async (importOriginal) => ({
	...(await importOriginal<object>()),
	default: { post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path: string) => path,
}))

const post = vi.mocked(axios.post)

/**
 * A rejected request as axios reports it; isAxiosError() checks the flag.
 *
 * @param status HTTP status of the app's answer
 * @param error Failure code in the app's answer
 */
function refused(status: number, error: string) {
	return {
		isAxiosError: true,
		message: 'Request failed',
		response: { status, data: { error } },
	}
}

afterEach(() => {
	post.mockReset()
})

describe('grantConsent', () => {
	it('reports a grant', async () => {
		post.mockResolvedValueOnce({ data: { success: true, granted: true } })

		expect(await grantConsent()).toEqual({
			granted: true,
			conflict: false,
			failure: null,
		})
		expect(post).toHaveBeenCalledWith(
			'/apps/synaplan_integration/api/v1/ai-consent',
			{ granted: true },
		)
	})

	it('asks for the account and reports an email conflict', async () => {
		post.mockResolvedValueOnce({ data: { success: false, conflict: true } })

		const answer = await grantConsent(true)

		expect(answer.conflict).toBe(true)
		expect(answer.granted).toBe(false)
		expect(post).toHaveBeenCalledWith(
			'/apps/synaplan_integration/api/v1/ai-consent',
			{ granted: true, create_account: true },
		)
	})

	it('turns a refused request into a failure to show', async () => {
		post.mockRejectedValueOnce(refused(409, 'email_already_used'))

		const answer = await grantConsent(true)

		expect(answer.granted).toBe(false)
		expect(answer.failure).toEqual(describeFailure('email_already_used'))
	})

	it('turns a lost connection into a failure to show', async () => {
		post.mockRejectedValueOnce(new Error('Network Error'))

		expect((await grantConsent()).failure).not.toBeNull()
	})
})
