import { t } from '@nextcloud/l10n'

export type CloudFileStateCode =
	'searchable' | 'adding' | 'updating' | 'changed' | 'failed' | 'none'

export type CloudBadge = 'clock' | 'arrows' | 'dot' | 'alert' | 'pause' | null

export interface GlyphOverrides {
	bird?: string | null
	bird_eyes?: string | null
}

const BIRD_PATH =
	'M23 11.5L19.95 10.37C19.69 9.22 19.04 8.56 19.04 8.56C17.4 6.92 14.75 6.92 13.11 8.56L11.63 10.04L5 3C4 7 5 11 7.45 14.22L2 19.5C2 19.5 10.89 21.5 16.07 17.45C18.83 15.29 19.45 14.03 19.84 12.7L23 11.5M17.71 11.72C17.32 12.11 16.68 12.11 16.29 11.72C15.9 11.33 15.9 10.7 16.29 10.31C16.68 9.92 17.32 9.92 17.71 10.31C18.1 10.7 18.1 11.33 17.71 11.72Z'

export const BIRD_SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path fill="currentColor" d="${BIRD_PATH}"/></svg>`

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
		? '<circle cx="15.5" cy="8.5" r="1.4" fill="currentColor" stroke="none"/><circle cx="18.5" cy="7.5" r="1.4" fill="currentColor" stroke="none"/>'
		: ''

	let badgePath = ''
	if (badge === 'clock') {
		badgePath =
			'<circle cx="12" cy="12" r="7" style="fill:none;stroke:currentColor;"/><path d="M12 9v4l2 2" style="fill:none;stroke:currentColor;"/>'
	} else if (badge === 'arrows') {
		badgePath =
			'<path d="M7 8h8l-2-2M17 16H9l2 2" style="fill:none;stroke:currentColor;"/>'
	} else if (badge === 'dot') {
		badgePath =
			'<circle cx="12" cy="12" r="4" style="fill:currentColor;stroke:none;"/>'
	} else if (badge === 'alert') {
		badgePath =
			'<path d="M12 5l7 12H5l7-12z" style="fill:none;stroke:currentColor;"/><path d="M12 10v3" style="fill:none;stroke:currentColor;"/>'
	} else if (badge === 'pause') {
		badgePath =
			'<path d="M9 7v10M15 7v10" style="fill:none;stroke:currentColor;"/>'
	}

	const badgeSvg = badgePath
		? `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="10" height="10" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="position:absolute;bottom:-2px;right:-4px;display:block;width:10px;height:10px;fill:none;stroke:currentColor;">${badgePath}</svg>`
		: ''

	return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="${size}" height="${size}" fill="currentColor" aria-hidden="true" style="display:block;width:${size}px;height:${size}px;fill:currentColor;"><path fill="currentColor" d="${BIRD_PATH}"/>${eyesMarkup}</svg>${badgeSvg}`
}
