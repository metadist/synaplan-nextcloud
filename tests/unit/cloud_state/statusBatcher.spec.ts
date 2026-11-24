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

describe('a watch', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.resetModules()
		post.mockReset()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	const watched = {
		data: {
			...answer('indexed').data,
			watches: [{ id: 5, target_id: '42', state: 'active' }],
		},
	}

	it('stopped elsewhere loses its eyes at the next answer', async () => {
		const { requestRowStatus } = await import('@/cloud_state/statusBatcher')
		post.mockResolvedValueOnce(watched).mockResolvedValue(answer('indexed'))

		const first = requestRowStatus('42', 'e1')
		await vi.advanceTimersByTimeAsync(50)
		expect((await first).watch).toMatchObject({ target_id: '42' })

		const later = requestRowStatus('42', 'e2')
		await vi.advanceTimersByTimeAsync(50)
		expect((await later).watch).toBeNull()
	})

	it('keeps its eyes while Synaplan gives no answer', async () => {
		const { requestRowStatus } = await import('@/cloud_state/statusBatcher')
		post.mockResolvedValueOnce(watched).mockResolvedValue({
			data: { enabled: true, unreachable: true, items: [], watches: [] },
		})

		const first = requestRowStatus('42', 'e1')
		await vi.advanceTimersByTimeAsync(50)
		await first

		const later = requestRowStatus('42', 'e2')
		await vi.advanceTimersByTimeAsync(50)
		expect((await later).watch).toMatchObject({ target_id: '42' })
	})
})
