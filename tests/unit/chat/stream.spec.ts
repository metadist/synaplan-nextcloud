import { afterEach, describe, expect, it, vi } from 'vitest'

import { readEvents, streamChat } from '@/chat/stream'

/**
 * A fetch answer whose body yields the given chunks, the shape streamChat reads.
 *
 * @param chunks Text chunks of the body, in order
 * @param init Status and body presence
 * @param init.ok Whether the status is a success
 * @param init.body Whether there is a body to read
 */
function answer(chunks: string[], init: { ok?: boolean; body?: boolean } = {}) {
	const encoder = new TextEncoder()
	let next = 0
	return {
		ok: init.ok ?? true,
		body:
			(init.body ?? true)
				? {
						getReader: () => ({
							read: async () =>
								next < chunks.length
									? {
											value: encoder.encode(chunks[next++]),
											done: false,
										}
									: { value: undefined, done: true },
						}),
					}
				: null,
	}
}

const delta = (content: string, model = 'gpt-oss-120b') =>
	`data: ${JSON.stringify({ model, choices: [{ delta: { content } }] })}\n\n`

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('readEvents', () => {
	it('returns the payloads of complete events and keeps the rest', () => {
		expect(
			readEvents('data: one\n\n: keep-alive\n\ndata: two\n\ndata: thr'),
		).toEqual({
			payloads: ['one', 'two'],
			rest: 'data: thr',
		})
	})
})

describe('streamChat', () => {
	it('hands every delta over and reports an answer', async () => {
		vi.stubGlobal(
			'fetch',
			vi
				.fn()
				.mockResolvedValue(
					answer([delta('Hel'), delta('lo'), 'data: [DONE]\n\n']),
				),
		)
		const deltas: string[] = []

		const outcome = await streamChat(
			'/stream',
			{ message: 'Hi' },
			(piece, model) => {
				deltas.push(`${piece}|${model}`)
			},
		)

		expect(outcome).toEqual({ kind: 'answered' })
		expect(deltas).toEqual(['Hel|gpt-oss-120b', 'lo|gpt-oss-120b'])
	})

	it('joins an event split across two chunks', async () => {
		const event = delta('Hello')
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue(answer([event.slice(0, 20), event.slice(20)])),
		)
		const deltas: string[] = []

		await streamChat('/stream', {}, (piece) => deltas.push(piece))

		expect(deltas).toEqual(['Hello'])
	})

	it('reports an error event as a failure', async () => {
		vi.stubGlobal(
			'fetch',
			vi
				.fn()
				.mockResolvedValue(
					answer([
						'data: {"error":{"message":"Model not available"}}\n\ndata: [DONE]\n\n',
					]),
				),
		)

		expect(await streamChat('/stream', {}, () => {})).toEqual({
			kind: 'failed',
			message: 'Model not available',
		})
	})

	it('reports nothing streamed when the request fails or the stream stays empty', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn().mockRejectedValue(new TypeError('Failed to fetch')),
		)
		expect(await streamChat('/stream', {}, () => {})).toEqual({
			kind: 'unavailable',
		})

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue(answer([], { ok: false })))
		expect(await streamChat('/stream', {}, () => {})).toEqual({
			kind: 'unavailable',
		})

		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue(answer(['data: [DONE]\n\n'])),
		)
		expect(await streamChat('/stream', {}, () => {})).toEqual({
			kind: 'unavailable',
		})
	})

	it('sends the CSRF token from the page head', async () => {
		document.head.setAttribute('data-requesttoken', 'token-1')
		const fetch = vi.fn().mockResolvedValue(answer([delta('ok')]))
		vi.stubGlobal('fetch', fetch)

		await streamChat('/stream', { message: 'Hi' }, () => {})

		expect(fetch).toHaveBeenCalledWith(
			'/stream',
			expect.objectContaining({
				method: 'POST',
				body: '{"message":"Hi"}',
				headers: expect.objectContaining({ requesttoken: 'token-1' }),
			}),
		)
	})
})
