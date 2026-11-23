import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export interface Features {
	translation: boolean
}

// Until Synaplan has answered, everything counts as offered: the request
// itself still refuses what Synaplan lacks, with a sentence.
let features: Features = { translation: true }
let loading: Promise<void> | null = null

/**
 * Read once what the connected Synaplan supports, so the Files actions
 * offer only that (R11). A failed read keeps everything offered.
 */
export function loadFeatures(): Promise<void> {
	if (loading === null) {
		loading = axios
			.get(generateUrl('/apps/synaplan_integration/api/v1/features'))
			.then(({ data }) => {
				features = { translation: data?.translation !== false }
			})
			.catch(() => {})
	}
	return loading
}

/**
 * Whether Synaplan offers translation, as far as is known yet.
 */
export function translationOffered(): boolean {
	return features.translation
}

/**
 * Forget what was read, for tests.
 */
export function resetFeatures(): void {
	features = { translation: true }
	loading = null
}
