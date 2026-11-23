import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { post: (...args: unknown[]) => post(...args) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => path }))

/**
 * Synaplan's answer for file 42 in one state.
 *
 * @param state the file's state
 */
function answer(state: string) {
	return {
		data: {
			enabled: true,
			items: [
				{
					id: '42',
					state,
					knowledge_folder: null,
					file_id: 9,
					kept_up_to_date: true,
				},
			],
			watches: [],
		},
	}
}

describe('an answer asked for before Remove', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.resetModules()
		post.mockReset()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('is neither shown nor kept: the row gets the answer asked for after it', async () => {
		const { invalidateCloudStatusCache, requestRowStatus } =
			await import('@/cloud_state/statusBatcher')
		let answerBeforeRemove: (value: unknown) => void = () => {}
		post.mockReturnValueOnce(
			new Promise((resolve) => (answerBeforeRemove = resolve)),
		).mockResolvedValue(answer('none'))

		const before = requestRowStatus('42', 'e1')
		await vi.advanceTimersByTimeAsync(50)
		invalidateCloudStatusCache()
		const after = requestRowStatus('42', 'e1')
		await vi.advanceTimersByTimeAsync(50)
		answerBeforeRemove(answer('indexed'))
		await vi.advanceTimersByTimeAsync(50)

		expect((await after).item?.state).toBe('none')
		expect((await before).item?.state).toBe('none')
		expect((await requestRowStatus('42', 'e1')).item?.state).toBe('none')
		expect(post).toHaveBeenCalledTimes(2)
	})

	it('a failure is asked again as well', async () => {
		const { invalidateCloudStatusCache, requestRowStatus } =
			await import('@/cloud_state/statusBatcher')
		let failBeforeRemove: (reason: unknown) => void = () => {}
		post.mockReturnValueOnce(
			new Promise((_resolve, reject) => (failBeforeRemove = reject)),
		).mockResolvedValue(answer('none'))

		const before = requestRowStatus('42', 'e1')
		await vi.advanceTimersByTimeAsync(50)
		invalidateCloudStatusCache()
		failBeforeRemove(new Error('Network Error'))
		await vi.advanceTimersByTimeAsync(50)

		expect(await before).toMatchObject({
			unreachable: false,
			item: { state: 'none' },
		})
	})
})
