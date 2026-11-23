import { t } from '@nextcloud/l10n'

/** The administrator's setting that holds a watch, if one does. */
export type PolicyOff = 'watches' | 'shared' | null

/** A watch as the app's GET /api/v1/watches lists it. */
export interface WatchRow {
	id: number
	kind: 'folder' | 'file'
	targetId: string
	name: string
	state: string
	sentence: string
	lastRunAt: number | null
	policyOff: PolicyOff
	openUrl: string
}

export interface WatchControls {
	pause: boolean
	resume: boolean
	stop: boolean
}

/**
 * Which of Pause, Resume and Stop the owner gets for a watch. Synaplan
 * resumes only a watch its owner paused; one it stopped for a reason, or one
 * the administrator's policy holds, can only be stopped.
 *
 * @param state The watch's state in Synaplan
 * @param policyOff The administrator's setting that holds the watch
 */
export function controlsFor(
	state: string,
	policyOff: PolicyOff | undefined = null,
): WatchControls {
	if (state === 'stopped') {
		return { pause: false, resume: false, stop: false }
	}
	if (policyOff) {
		return { pause: false, resume: false, stop: true }
	}
	return {
		pause: state === 'active',
		resume: state === 'paused',
		stop: true,
	}
}

/**
 * What a watch the administrator's setting holds reads instead of
 * Synaplan's sentence.
 *
 * @param policyOff The setting that holds the watch
 */
export function policyStopped(policyOff: 'watches' | 'shared'): string {
	return policyOff === 'watches'
		? t(
				'synaplan_integration',
				'Stopped — your administrator turned off watching folders',
			)
		: t(
				'synaplan_integration',
				'Stopped — your administrator turned off watching shared folders',
			)
}
