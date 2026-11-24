import { isAxiosError } from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * A failed action as one sentence, and the page where the person can fix it
 * when that is their own Synaplan settings. The codes come from
 * lib/Http/Failure.php.
 */
export interface Failure {
	text: string
	settingsUrl?: string
}

// The personal settings section is registered under the app id.
/**
 *
 */
function synaplanSettings(): string {
	return generateUrl('/settings/user/synaplan_integration')
}

/**
 * The app's sentence, followed by Synaplan's own wording when it sent a reason.
 *
 * @param summary The sentence for this failure code
 * @param message Synaplan's sentence, when it sent one
 */
function explained(summary: string, message?: string): string {
	const reason = message?.trim()
	if (!reason || reason === summary) {
		return summary
	}
	return t('synaplan_integration', '{summary} {reason}', { summary, reason })
}

/**
 * Fired on window when an answer says the person's consent or connection is
 * gone, so a consent gate that is already open reads its state again.
 */
export const CONSENT_STALE_EVENT = 'synaplan:consent-stale'

const CONSENT_STALE_CODES = new Set(['not_connected', 'consent_required'])

/**
 * Call listener whenever an answer says consent or the connection is gone.
 *
 * @param listener Reads the consent state again
 * @return Stops listening
 */
export function onConsentStale(listener: () => void): () => void {
	window.addEventListener(CONSENT_STALE_EVENT, listener)
	return () => window.removeEventListener(CONSENT_STALE_EVENT, listener)
}

/** What Synaplan named beside the code: which module, and which right is missing. */
export interface FailureContext {
	module?: string
	required?: string[]
}

const MACHINE_CODE = /^[a-z][a-z0-9_]*$/

/**
 * A right the person's Synaplan connection is missing, in words.
 *
 * @param scope A scope Synaplan named, such as cloud:watch
 */
function rightFor(scope: string): string {
	switch (scope) {
		case 'cloud:watch':
			return t('synaplan_integration', 'watching folders and files')
		case 'files':
		case 'files:*':
			return t('synaplan_integration', 'using files')
		case 'chat':
		case 'chats:*':
			return t('synaplan_integration', 'chatting')
		case 'rag':
		case 'rag:*':
			return t('synaplan_integration', 'searching your knowledge')
		case 'memories':
			return t('synaplan_integration', 'using memories')
		case 'audio:transcribe':
			return t('synaplan_integration', 'transcribing audio')
		case 'messages:*':
			return t('synaplan_integration', 'reading messages')
		default:
			return ''
	}
}

/**
 * The missing rights as one phrase, when Synaplan named any this app knows.
 *
 * @param required Scopes Synaplan said this action needs
 */
function missingRight(required?: string[]): string | undefined {
	if (!required || required.length === 0) {
		return undefined
	}
	const phrases = [
		...new Set(
			required
				.map((scope) => rightFor(scope))
				.filter((phrase) => phrase !== ''),
		),
	]
	return phrases.length > 0 ? phrases.join(', ') : undefined
}

/**
 * The module Synaplan sent, when it is an id and not a sentence.
 *
 * @param body One answer from the app
 */
function contextFrom(body: Record<string, unknown>): FailureContext {
	const module = typeof body.module === 'string' ? body.module : undefined
	const required = Array.isArray(body.required)
		? body.required.filter((item): item is string => typeof item === 'string')
		: undefined
	return {
		module: module && MACHINE_CODE.test(module) ? module : undefined,
		required: required && required.length > 0 ? required : undefined,
	}
}

/**
 * The sentence for a failure code. A code the app does not know shows
 * Synaplan's own sentence when it sent one beside the code. A sentence this
 * app put in the code field stays the general sentence, so the caller can
 * keep its translated fallback.
 *
 * @param code Failure code from the app's answer
 * @param message Synaplan's sentence, when it sent one
 * @param context The module or the missing right, when Synaplan named one
 */
export function describeFailure(
	code?: string,
	message?: string,
	context?: FailureContext,
): Failure {
	if (code && CONSENT_STALE_CODES.has(code)) {
		window.dispatchEvent(new CustomEvent(CONSENT_STALE_EVENT))
	}
	switch (code) {
		case 'not_connected':
			return {
				text: t(
					'synaplan_integration',
					'Connect your Synaplan account first.',
				),
				settingsUrl: synaplanSettings(),
			}
		case 'insufficient_scope': {
			const right = missingRight(context?.required)
			return {
				text: right
					? t(
							'synaplan_integration',
							'Your Synaplan connection does not allow {right}. Connect your Synaplan account again.',
							{ right },
						)
					: t(
							'synaplan_integration',
							'Your Synaplan connection does not allow this. Connect your Synaplan account again.',
						),
				settingsUrl: synaplanSettings(),
			}
		}
		case 'feature_not_configured':
			return {
				text: explained(
					context?.module === 'cloud_files'
						? t(
								'synaplan_integration',
								'Watching folders and files is turned off on this Synaplan. Ask your administrator to turn it on.',
							)
						: t(
								'synaplan_integration',
								'This is turned off on this Synaplan. Ask your administrator.',
							),
					message,
				),
			}
		case 'platform_disconnected':
			return {
				text:
					message && /turned off/i.test(message)
						? t(
								'synaplan_integration',
								'Synaplan is turned off for this Nextcloud. Ask your administrator.',
							)
						: explained(
								t(
									'synaplan_integration',
									'This Nextcloud is not linked to Synaplan. Ask your administrator.',
								),
								message && /not linked/i.test(message)
									? undefined
									: message,
							),
			}
		case 'platform_ambiguous':
			return {
				text: t(
					'synaplan_integration',
					'More than one Synaplan connection is registered for your login. Ask your administrator to remove the one no longer in use.',
				),
			}
		case 'watch_cap':
			return {
				text: explained(
					t(
						'synaplan_integration',
						'You have reached the limit for watching folders and files.',
					),
					message,
				),
			}
		case 'storage_quota':
			return {
				text: explained(
					t(
						'synaplan_integration',
						'There is no space left in your Synaplan storage.',
					),
					message,
				),
			}
		case 'invalid_watch':
			return {
				text: explained(
					t('synaplan_integration', 'Synaplan could not use this watch.'),
					message,
				),
			}
		case 'model_not_found':
			return {
				text: message?.trim()
					? message.trim()
					: t(
							'synaplan_integration',
							'No model is set up for this. Ask your administrator.',
						),
			}
		case 'no_access':
			return {
				text: t(
					'synaplan_integration',
					'You do not have access to this folder or file.',
				),
			}
		case 'consent_required':
			return {
				text: t(
					'synaplan_integration',
					'Activate AI first, then try again.',
				),
			}
		case 'account_unavailable':
			return {
				text: explained(
					t(
						'synaplan_integration',
						'Synaplan could not set up your account. Ask your administrator.',
					),
					message,
				),
			}
		case 'api_plan_required':
			return {
				text: t(
					'synaplan_integration',
					'Synaplan could not set up your account: API keys are part of a paid plan.',
				),
			}
		case 'email_already_used':
			return {
				text: t(
					'synaplan_integration',
					'Synaplan could not set up your account: this email address already has a Synaplan account.',
				),
			}
		case 'admin_key_missing':
			return {
				text: t(
					'synaplan_integration',
					'Synaplan could not set up your account: this Nextcloud has no key that can create accounts.',
				),
			}
		case 'synaplan_unreachable':
			return {
				text: explained(
					t(
						'synaplan_integration',
						'Synaplan cannot be reached. Try again in a minute, or ask your administrator.',
					),
					message,
				),
			}
		case 'synaplan_error':
			return {
				text: explained(
					t(
						'synaplan_integration',
						'Synaplan could not finish this. Try again in a minute, or ask your administrator.',
					),
					message,
				),
			}
		case 'synaplan_key_rejected':
			return {
				text: explained(
					t(
						'synaplan_integration',
						'Synaplan does not accept the key of this Nextcloud. Ask your administrator to check the Synaplan settings.',
					),
					message,
				),
			}
		case 'rate_limited':
			return {
				text: t(
					'synaplan_integration',
					'Synaplan is busy or your limit is reached. Try again later.',
				),
			}
		case 'file_not_found':
			return {
				text: t(
					'synaplan_integration',
					'This file is no longer there. Reload the folder and try again.',
				),
			}
		case 'file_too_large':
			return {
				text: t(
					'synaplan_integration',
					'This file is too large for Synaplan. Try a smaller file.',
				),
			}
		case 'copy_too_large':
			return {
				text: t(
					'synaplan_integration',
					'This file is larger than 100 MB, more than Synaplan copies into your files.',
				),
			}
		case 'storage_full':
			return {
				text: t(
					'synaplan_integration',
					'This file does not fit into your storage. Make room and try again.',
				),
			}
		case 'name_taken':
			return {
				text: t(
					'synaplan_integration',
					'Twenty files with this name are already in that folder. Rename or remove one and try again.',
				),
			}
		case 'unsupported_type':
			return {
				text: t(
					'synaplan_integration',
					'Synaplan cannot read this kind of file.',
				),
			}
		case 'file_empty':
			return { text: t('synaplan_integration', 'This file is empty.') }
		case 'no_text':
			return {
				text: t(
					'synaplan_integration',
					'Synaplan found no text in this file. It may be a scan or protected by a password.',
				),
			}
		case 'watch_needs_edit':
			return {
				text: t(
					'synaplan_integration',
					'You need permission to edit this folder or file before you can watch it.',
				),
			}
		case 'watches_off':
			return {
				text: t(
					'synaplan_integration',
					'Your administrator turned off watching folders.',
				),
			}
		case 'watch_shared_off':
			return {
				text: t(
					'synaplan_integration',
					'Your administrator turned off watching shared folders.',
				),
			}
		case 'folder_reads_off':
			return {
				text: t(
					'synaplan_integration',
					'This Synaplan does not read folders from Nextcloud.',
				),
			}
		case 'translation_unavailable':
			return {
				text: t(
					'synaplan_integration',
					'This Synaplan cannot translate a file. Ask your administrator.',
				),
			}
	}
	if (message) {
		return { text: message }
	}
	return {
		text: t(
			'synaplan_integration',
			'Something went wrong. Try again, or ask your administrator.',
		),
	}
}

/**
 * The failure in an answer body, including a module or a missing right.
 *
 * @param data What the app answered
 */
export function failureFromAnswer(data: unknown): Failure {
	if (!data || typeof data !== 'object') {
		return describeFailure()
	}
	const body = data as Record<string, unknown>
	const error = body.error
	if (error && typeof error === 'object') {
		const nested = error as Record<string, unknown>
		return describeFailure(
			typeof nested.code === 'string' ? nested.code : undefined,
			typeof nested.message === 'string' ? nested.message : undefined,
			contextFrom(nested),
		)
	}
	return describeFailure(
		typeof body.error === 'string' ? body.error : undefined,
		typeof body.message === 'string' ? body.message : undefined,
		contextFrom(body),
	)
}

/**
 * The specific sentence, or the caller's own when the app only knows that
 * something failed.
 *
 * @param error What axios threw
 * @param fallback The sentence for this action
 */
export function failureOr(error: unknown, fallback: string): string {
	const failure = failureFromError(error)
	return failure.text === describeFailure().text ? fallback : failure.text
}

/**
 * The specific sentence in an answer, or the caller's own.
 *
 * @param data What the app answered
 * @param fallback The sentence for this action
 */
export function answerOr(data: unknown, fallback: string): string {
	const failure = failureFromAnswer(data)
	return failure.text === describeFailure().text ? fallback : failure.text
}

/**
 * The failure behind a rejected request to the app.
 *
 * @param error What axios threw
 */
export function failureFromError(error: unknown): Failure {
	if (isAxiosError(error)) {
		return failureFromAnswer(error.response?.data)
	}
	return describeFailure()
}
