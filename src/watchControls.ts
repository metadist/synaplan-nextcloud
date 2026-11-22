/** A watch as the app's GET /api/v1/watches lists it. */
export interface WatchRow {
	id: number
	kind: 'folder' | 'file'
	targetId: string
	name: string
	state: string
	sentence: string
	lastRunAt: number | null
	policyOff: boolean
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
 * @param policyOff The administrator turned off watching shared folders
 */
export function controlsFor(state: string, policyOff = false): WatchControls {
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
