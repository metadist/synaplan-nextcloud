import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export interface Features {
	translation: boolean
	watches: boolean
	watchShared: boolean
	readFolders: boolean
	followsChanges: boolean
}

// Until Synaplan has answered, everything counts as offered: the request
// itself still refuses what Synaplan lacks, with a sentence. Keeping a file
// up to date is a promise to the person, so it waits for the answer.
let features: Features = {
	translation: true,
	watches: true,
	watchShared: true,
	readFolders: true,
	followsChanges: false,
}
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
				features = {
					translation: data?.translation !== false,
					watches: data?.watches !== false,
					watchShared: data?.watchShared !== false,
					readFolders: data?.readFolders !== false,
					followsChanges: data?.followsChanges === true,
				}
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
 * Whether the administrator allows watching folders at all and Synaplan
 * takes watches, as far as is known yet.
 */
export function watchingAllowed(): boolean {
	return features.watches
}

/**
 * Whether the administrator allows watching shared folders, as far as is
 * known yet.
 */
export function watchSharedAllowed(): boolean {
	return features.watchShared
}

/**
 * Whether the administrator lets people name folders for Synaplan to read,
 * as far as is known yet.
 */
export function readFoldersAllowed(): boolean {
	return features.readFolders
}

/**
 * Whether Synaplan hears from here when the person's files change, so a file
 * can be kept up to date. False until known.
 */
export function changesFollowed(): boolean {
	return features.followsChanges
}

/**
 * Forget what was read, for tests.
 */
export function resetFeatures(): void {
	features = {
		translation: true,
		watches: true,
		watchShared: true,
		readFolders: true,
		followsChanges: false,
	}
	loading = null
}
