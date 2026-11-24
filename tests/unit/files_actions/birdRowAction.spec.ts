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

/**
 * The file's row, drawn and on the page.
 */
async function drawnRow(): Promise<HTMLElement> {
	const { birdRowAction } = await import('@/files_actions/birdRowAction')
	const drawn = birdRowAction.renderInline!({
		nodes: [{ fileid: 42, attributes: { etag: 'e1' } }],
	} as never)
	await vi.advanceTimersByTimeAsync(50)
	const slot = (await drawn) as HTMLElement
	document.body.appendChild(slot)
	return slot
}

/**
 * The state the row's bird shows, or null without a bird.
 *
 * @param slot the row's slot
 */
function birdState(slot: HTMLElement): string | null {
	return (
		slot
			.querySelector('[data-synaplan-state]')
			?.getAttribute('data-synaplan-state') ?? null
	)
}

describe('a bird Synaplan is still at work on', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.resetModules()
		post.mockReset()
		document.body.innerHTML = ''
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('turns green on its own once Synaplan is done', async () => {
		post.mockResolvedValueOnce(answer('updating'))
			.mockResolvedValueOnce(answer('updating'))
			.mockResolvedValue(answer('indexed'))
		const slot = await drawnRow()
		expect(birdState(slot)).toBe('updating')

		await vi.advanceTimersByTimeAsync(10_050)
		expect(birdState(slot)).toBe('updating')
		await vi.advanceTimersByTimeAsync(30_050)
		expect(birdState(slot)).toBe('indexed')

		await vi.advanceTimersByTimeAsync(3_600_000)
		expect(post).toHaveBeenCalledTimes(3)
	})

	it('shows up once Synaplan answers again', async () => {
		post.mockRejectedValueOnce(new Error('Network Error')).mockResolvedValue(
			answer('indexed'),
		)
		const slot = await drawnRow()
		expect(birdState(slot)).toBeNull()

		await vi.advanceTimersByTimeAsync(10_050)
		expect(birdState(slot)).toBe('indexed')
	})

	it('starts its waits over when the cache is cleared while it asks again', async () => {
		let answerFollowUp: (value: unknown) => void = () => {}
		post.mockResolvedValueOnce(answer('updating'))
			.mockImplementationOnce(
				() =>
					new Promise((resolve) => {
						answerFollowUp = resolve
					}),
			)
			.mockResolvedValue(answer('updating'))
		const slot = await drawnRow()
		const { invalidateCloudStatusCache } =
			await import('@/cloud_state/statusBatcher')

		await vi.advanceTimersByTimeAsync(10_050)
		expect(post).toHaveBeenCalledTimes(2)
		invalidateCloudStatusCache()
		await vi.advanceTimersByTimeAsync(50)
		answerFollowUp(answer('updating'))
		await vi.advanceTimersByTimeAsync(50)
		expect(birdState(slot)).toBe('updating')
		const asked = post.mock.calls.length

		await vi.advanceTimersByTimeAsync(10_000)
		expect(post.mock.calls.length).toBe(asked + 1)
	})

	it('stops asking after the last wait', async () => {
		post.mockResolvedValue(answer('adding'))
		await drawnRow()

		await vi.advanceTimersByTimeAsync(24 * 3_600_000)
		expect(post).toHaveBeenCalledTimes(6)
	})

	it('stops asking once its row is gone', async () => {
		post.mockResolvedValue(answer('adding'))
		const slot = await drawnRow()
		slot.remove()

		await vi.advanceTimersByTimeAsync(3_600_000)
		expect(post).toHaveBeenCalledTimes(1)
	})
})
