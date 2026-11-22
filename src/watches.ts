import type { WatchRow } from './watchControls'

import axios from '@nextcloud/axios'
import { showConfirmation, showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { failureFromError } from './failures'

const baseUrl = generateUrl('/apps/synaplan_integration')

/**
 * The person's watches that are not stopped.
 */
export async function listWatches(): Promise<WatchRow[]> {
	const { data } = await axios.get(`${baseUrl}/api/v1/watches`)
	return Array.isArray(data?.watches) ? data.watches : []
}

/**
 * Pause or resume a watch, saying why when Synaplan refuses.
 *
 * @param id Synaplan watch id
 * @param state The state the owner asked for
 * @return The watch as it is now, or null when it failed
 */
export async function setWatchState(
	id: number,
	state: 'paused' | 'active',
): Promise<WatchRow | null> {
	try {
		const { data } = await axios.patch(`${baseUrl}/api/v1/watches/${id}`, {
			state,
		})
		return data?.watch ?? null
	} catch (error) {
		showError(failureFromError(error).text)
		return null
	}
}

/**
 * Ask once, then stop the watch.
 *
 * @param id Synaplan watch id
 * @param name The watched folder or file, as the person knows it
 * @return Whether the watch stopped
 */
export async function stopWatch(id: number, name: string): Promise<boolean> {
	let confirmed: boolean
	try {
		confirmed = await showConfirmation({
			name: t('synaplan_integration', 'Stop watching'),
			text: t(
				'synaplan_integration',
				'Synaplan stops watching {name}. Files and translations already made stay where they are.',
				{ name },
			),
			labelConfirm: t('synaplan_integration', 'Stop watching'),
			labelReject: t('synaplan_integration', 'Cancel'),
			severity: 'warning',
		})
	} catch {
		return false
	}
	if (!confirmed) {
		return false
	}
	try {
		await axios.delete(`${baseUrl}/api/v1/watches/${id}`)
		return true
	} catch (error) {
		showError(failureFromError(error).text)
		return false
	}
}
