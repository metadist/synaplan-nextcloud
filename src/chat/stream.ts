/**
 * The research chat's streaming request: posts the question to the app's SSE
 * proxy (lib/Http/SseProxyResponse.php) and hands each answer delta to the
 * caller as it arrives.
 */

export type StreamOutcome =
	/** Deltas arrived and the stream ended without an error. */
	| { kind: 'answered' }
	/** The app refused the request or the stream ended with an error event. */
	| { kind: 'failed'; code?: string; message?: string }
	/** No answer could be streamed; the caller may ask the blocking endpoint. */
	| { kind: 'unavailable' }

export type DeltaHandler = (delta: string, model?: string) => void

/**
 * Nextcloud's CSRF request token from the page head — the source the Nextcloud
 * auth helper uses — so a plain fetch() passes the CSRF check.
 */
export function csrfToken(): string {
	const head = document.getElementsByTagName('head')[0]
	return head?.getAttribute('data-requesttoken') ?? ''
}

/**
 * Split an SSE buffer into the `data:` payloads of its complete events and the
 * unfinished rest, which waits for the next chunk.
 *
 * @param buffer Text received so far
 */
export function readEvents(buffer: string): { payloads: string[]; rest: string } {
	const events = buffer.split('\n\n')
	const rest = events.pop() ?? ''
	const payloads: string[] = []
	for (const event of events) {
		const dataLine = event.split('\n').find((line) => line.startsWith('data:'))
		if (dataLine) {
			payloads.push(dataLine.slice(5).trim())
		}
	}
	return { payloads, rest }
}

/**
 * Stream one answer.
 *
 * @param url The app's stream endpoint
 * @param payload The chat payload (message, optional groupKey, model, useMemories)
 * @param onDelta Called for every piece of the answer
 */
export async function streamChat(
	url: string,
	payload: Record<string, unknown>,
	onDelta: DeltaHandler,
): Promise<StreamOutcome> {
	let answered = false
	let failure: { code?: string; message?: string } | null = null

	try {
		const response = await fetch(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'text/event-stream',
				requesttoken: csrfToken(),
			},
			body: JSON.stringify(payload),
		})

		if (!response.ok) {
			return refusal(response)
		}
		if (!response.body) {
			return { kind: 'unavailable' }
		}

		const reader = response.body.getReader()
		const decoder = new TextDecoder()
		let buffer = ''

		for (;;) {
			const { value, done } = await reader.read()
			if (done) {
				break
			}
			buffer += decoder.decode(value, { stream: true })

			const { payloads, rest } = readEvents(buffer)
			buffer = rest
			for (const data of payloads) {
				if (data === '' || data === '[DONE]') {
					continue
				}
				try {
					const json = JSON.parse(data)
					if (json.error) {
						failure = {
							code: json.error.code,
							message: json.error.message,
						}
						continue
					}
					const delta = json.choices?.[0]?.delta?.content
					if (typeof delta === 'string' && delta.length > 0) {
						answered = true
						onDelta(
							delta,
							typeof json.model === 'string' ? json.model : undefined,
						)
					}
				} catch {
					// Ignore keep-alives and partial frames.
				}
			}
		}
	} catch {
		return answered ? { kind: 'answered' } : { kind: 'unavailable' }
	}

	if (failure && (failure.code || failure.message)) {
		return { kind: 'failed', ...failure }
	}
	return answered ? { kind: 'answered' } : { kind: 'unavailable' }
}

/**
 * The app answered the stream request with an error status. Its code (not
 * connected, file not found) is final; an answer without one lets the caller
 * try the blocking endpoint.
 *
 * @param response The app's answer
 */
async function refusal(response: Pick<Response, 'json'>): Promise<StreamOutcome> {
	try {
		const data = await response.json()
		if (typeof data?.error === 'string') {
			return { kind: 'failed', code: data.error, message: data.message }
		}
	} catch {
		// Not the app's JSON: a proxy or server page.
	}
	return { kind: 'unavailable' }
}
