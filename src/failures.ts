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
const synaplanSettings = (): string =>
	generateUrl('/settings/user/synaplan_integration')

/**
 * The sentence for a failure code. A code the app does not know shows
 * Synaplan's own sentence when there is one.
 *
 * @param code Failure code from the app's answer
 * @param message Synaplan's sentence, when it sent one
 */
export function describeFailure(code?: string, message?: string): Failure {
	switch (code) {
		case 'not_connected':
			return {
				text: t(
					'synaplan_integration',
					'Connect your Synaplan account first.',
				),
				settingsUrl: synaplanSettings(),
			}
		case 'insufficient_scope':
			return {
				text: t(
					'synaplan_integration',
					'Your Synaplan connection does not allow this. Connect your Synaplan account again.',
				),
				settingsUrl: synaplanSettings(),
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
				text: t(
					'synaplan_integration',
					'Synaplan could not set up your account. Ask your administrator.',
				),
			}
		case 'synaplan_unreachable':
			return {
				text: t(
					'synaplan_integration',
					'Synaplan cannot be reached. Try again in a minute, or ask your administrator.',
				),
			}
		case 'synaplan_error':
			return {
				text: t(
					'synaplan_integration',
					'Synaplan could not finish this. Try again in a minute, or ask your administrator.',
				),
			}
		case 'synaplan_key_rejected':
			return {
				text: t(
					'synaplan_integration',
					'Synaplan does not accept the key of this Nextcloud. Ask your administrator to check the Synaplan settings.',
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
 * The failure behind a rejected request to the app.
 *
 * @param error What axios threw
 */
export function failureFromError(error: unknown): Failure {
	if (isAxiosError(error)) {
		const data = error.response?.data
		return describeFailure(data?.error, data?.message)
	}
	return describeFailure()
}
