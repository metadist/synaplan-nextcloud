import { t } from '@nextcloud/l10n'

export type CloudFileStateCode =
	'searchable' | 'adding' | 'updating' | 'changed' | 'failed' | 'none'

export type CloudBadge = 'clock' | 'arrows' | 'dot' | 'alert' | 'pause' | null

export interface GlyphOverrides {
	bird?: string | null
	bird_eyes?: string | null
}

export const BIRD_SVG =
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14c2-6 6-8 9-7 2 .6 3 2 4 2 2 0 4-1.5 4-1.5"/><path d="M13 7c1 3 1 6-1 8-2 2-5 3-8 2 1-2 2-4 2-6"/><path d="M8 16c1 2 3 3 5 3"/></svg>'

/**
 * Badge type for a file state or watch state (plan 04 §A.1).
 *
 * @param {CloudFileStateCode} state File state code
 * @param {string | null} watchState Optional covering watch state
 */
export function badgeForState(
	state: CloudFileStateCode,
	watchState: string | null = null,
): CloudBadge {
	if (watchState === 'attention') {
		return 'alert'
	}
	if (watchState === 'paused') {
		return 'pause'
	}
	switch (state) {
		case 'adding':
			return 'clock'
		case 'updating':
			return 'arrows'
		case 'changed':
			return 'dot'
		case 'failed':
			return 'alert'
		default:
			return null
	}
}

/**
 * Tooltip and aria-label sentence for a file row glyph (plan 04 §A.1, 05 §3).
 *
 * @param {CloudFileStateCode} state File state code
 * @param {string | null} folder Knowledge folder name
 * @param {string | null} watchSentence Covering watch sentence
 * @param {string | null} watchState Covering watch state
 */
export function labelForState(
	state: CloudFileStateCode,
	folder: string | null = null,
	watchSentence: string | null = null,
	watchState: string | null = null,
): string {
	let filePart = ''
	switch (state) {
		case 'searchable':
			filePart = folder
				? t('synaplan_integration', 'Searchable by AI — in “{folder}”', {
						folder,
					})
				: t('synaplan_integration', 'Searchable by AI')
			break
		case 'adding':
			filePart = t('synaplan_integration', 'Being made searchable…')
			break
		case 'updating':
			filePart = t('synaplan_integration', 'Changed — Synaplan is updating it')
			break
		case 'changed':
			filePart = t(
				'synaplan_integration',
				'Changed since it was made searchable — open to update',
			)
			break
		case 'failed':
			filePart = t(
				'synaplan_integration',
				'Could not be made searchable — open to see why',
			)
			break
		default:
			filePart = ''
	}

	let watchPart = ''
	if (watchState === 'paused') {
		watchPart = t('synaplan_integration', 'Watching paused — open to resume')
	} else if (watchState === 'attention') {
		watchPart = t('synaplan_integration', 'Watching stopped — open to see why')
	} else if (watchSentence) {
		watchPart = watchSentence
	}

	if (watchPart && filePart) {
		return `${watchPart} · ${filePart}`
	}
	return watchPart || filePart
}

/**
 * Build the SVG markup for a bird or bird-with-eyes plus an optional badge.
 *
 * @param {object} options Rendering options
 * @param {boolean} options.eyes Whether to include the eyes
 * @param {CloudBadge} options.badge Optional corner badge
 * @param {16 | 20} options.size Pixel size (16 or 20)
 * @param {GlyphOverrides} options.overrides Optional runtime-config URLs
 */
export function glyphMarkup(options: {
	eyes?: boolean
	badge?: CloudBadge
	size?: 16 | 20
	overrides?: GlyphOverrides
}): string {
	const eyes = Boolean(options.eyes)
	const badge = options.badge ?? null
	const size = options.size ?? 16
	const overrideUrl = eyes ? options.overrides?.bird_eyes : options.overrides?.bird

	if (overrideUrl) {
		const safeUrl = overrideUrl.replace(/"/g, '&quot;')
		return `<img src="${safeUrl}" alt="" width="${size}" height="${size}" style="display:block;width:${size}px;height:${size}px;" />`
	}

	const eyesMarkup = eyes
		? '<circle cx="15.5" cy="8.5" r="0.8" fill="currentColor" stroke="none"/><circle cx="18" cy="8" r="0.8" fill="currentColor" stroke="none"/>'
		: ''

	let badgePath = ''
	if (badge === 'clock') {
		badgePath = '<circle cx="12" cy="12" r="7"/><path d="M12 9v4l2 2"/>'
	} else if (badge === 'arrows') {
		badgePath = '<path d="M7 8h8l-2-2M17 16H9l2 2"/>'
	} else if (badge === 'dot') {
		badgePath =
			'<circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/>'
	} else if (badge === 'alert') {
		badgePath = '<path d="M12 5l7 12H5l7-12z"/><path d="M12 10v3"/>'
	} else if (badge === 'pause') {
		badgePath = '<path d="M9 7v10M15 7v10"/>'
	}

	const badgeSvg = badgePath
		? `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="position:absolute;bottom:-2px;right:-4px;display:block;width:10px;height:10px;">${badgePath}</svg>`
		: ''

	return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:block;width:${size}px;height:${size}px;"><path d="M4 14c2-6 6-8 9-7 2 .6 3 2 4 2 2 0 4-1.5 4-1.5"/><path d="M13 7c1 3 1 6-1 8-2 2-5 3-8 2 1-2 2-4 2-6"/><path d="M8 16c1 2 3 3 5 3"/>${eyesMarkup}</svg>${badgeSvg}`
}
