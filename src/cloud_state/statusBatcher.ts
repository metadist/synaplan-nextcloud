import type { CloudFileStateCode, GlyphOverrides } from './glyphs'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export interface CloudStatusItem {
	id: string
	state: CloudFileStateCode
	knowledge_folder: { key: string; name: string } | null
	file_id: number | null
	kept_up_to_date: boolean
}

export interface CloudWatchSummary {
	id: number
	target_id: string
	sentence: string
	state: string
	/** The administrator turned off watching shared folders. */
	policy_off?: 'watches' | 'shared'
}

export interface ResolvedRowStatus {
	enabled: boolean
	unreachable: boolean
	item: CloudStatusItem | null
	watch: CloudWatchSummary | null
	glyphs: GlyphOverrides
	openSynaplanUrl: string
}

interface CacheEntry {
	expiresAt: number
	value: ResolvedRowStatus
}

const CACHE_TTL_MS = 60_000
/** How long a row waits each time before asking again while Synaplan is still at work on its file, or gave no answer. */
export const REFRESH_DELAYS_MS = [10_000, 30_000, 60_000, 120_000, 300_000]
const BATCH_WINDOW_MS = 50
const MAX_BATCH = 500

const cache = new Map<string, CacheEntry>()
const folderWatches = new Map<string, CloudWatchSummary>()
let featureEnabled: boolean | null = null
let lastGlyphs: GlyphOverrides = {}
let lastOpenSynaplanUrl = ''
const listeners = new Set<() => void>()
/** Counts invalidations: an answer asked for before the last one is out of date. */
let generation = 0

interface PendingItem {
	id: string
	version: string
	resolvers: Array<(res: ResolvedRowStatus) => void>
}

const pending = new Map<string, PendingItem>()
let flushTimer: ReturnType<typeof setTimeout> | null = null

/**
 * The cache key of a file in one version.
 *
 * @param id Nextcloud file id
 * @param version its etag
 */
function cacheKey(id: string, version: string): string {
	return `${id}:${version}`
}

/**
 * Whether a row's answer will not change on its own: Synaplan answered and
 * is not adding or updating the file.
 *
 * @param res the row's answer
 */
function settled(res: ResolvedRowStatus): boolean {
	if (res.unreachable) {
		return false
	}
	if (!res.enabled) {
		return true
	}
	return res.item?.state !== 'adding' && res.item?.state !== 'updating'
}

/**
 * How long a row shown with this answer waits before asking again, or null
 * when it need not ask.
 *
 * @param res the row's answer
 * @param attempt how often the row asked again since it settled last
 */
export function refreshDelay(
	res: ResolvedRowStatus,
	attempt: number,
): number | null {
	return settled(res) ? null : (REFRESH_DELAYS_MS[attempt] ?? null)
}

/**
 * Subscribe to status cache invalidations (after Make searchable or Remove).
 *
 * @param fn Listener callback
 */
export function onCloudStatusInvalidated(fn: () => void): () => void {
	listeners.add(fn)
	return () => listeners.delete(fn)
}

/**
 * Clear the 60-second client cache after a knowledge change.
 */
export function invalidateCloudStatusCache(): void {
	generation++
	cache.clear()
	folderWatches.clear()
	featureEnabled = null
	listeners.forEach((fn) => fn())
}

/**
 * Whether the connected Synaplan has cloud_files enabled (null before first check).
 */
export function isCloudStatusEnabled(): boolean | null {
	return featureEnabled
}

/**
 * Return any cached watch summary for a folder or file id.
 *
 * @param targetId Nextcloud fileid as string
 */
export function watchForTarget(targetId: string): CloudWatchSummary | null {
	return folderWatches.get(targetId) ?? null
}

/**
 * Request the searchable state for one row in the current folder view.
 * Collects all visible rows rendered within 50 ms into one batched POST.
 *
 * @param id Nextcloud fileid as string
 * @param version Nextcloud etag
 */
export function requestRowStatus(
	id: string,
	version: string,
): Promise<ResolvedRowStatus> {
	if (featureEnabled === false) {
		return Promise.resolve({
			enabled: false,
			unreachable: false,
			item: null,
			watch: null,
			glyphs: lastGlyphs,
			openSynaplanUrl: lastOpenSynaplanUrl,
		})
	}

	const key = cacheKey(id, version)
	const hit = cache.get(key)
	if (hit && hit.expiresAt > Date.now()) {
		return Promise.resolve(hit.value)
	}

	return new Promise<ResolvedRowStatus>((resolve) => {
		const existing = pending.get(key)
		if (existing) {
			existing.resolvers.push(resolve)
		} else {
			pending.set(key, { id, version, resolvers: [resolve] })
		}
		if (flushTimer === null) {
			flushTimer = setTimeout(flushBatch, BATCH_WINDOW_MS)
		}
	})
}

/**
 *
 */
async function flushBatch(): Promise<void> {
	flushTimer = null
	const entries = [...pending.values()]
	pending.clear()
	if (entries.length === 0) {
		return
	}

	const baseUrl = generateUrl('/apps/synaplan_integration')
	for (let offset = 0; offset < entries.length; offset += MAX_BATCH) {
		const slice = entries.slice(offset, offset + MAX_BATCH)
		const asked = generation
		try {
			const { data } = await axios.post(`${baseUrl}/api/v1/cloud/status`, {
				items: slice.map((e) => ({ id: e.id, version: e.version })),
			})
			if (asked !== generation) {
				askAgain(slice)
				continue
			}
			const enabled = Boolean(data?.enabled)
			const unreachable = Boolean(data?.unreachable)
			featureEnabled = enabled
			if (data?.glyphs && typeof data.glyphs === 'object') {
				lastGlyphs = data.glyphs as GlyphOverrides
			}
			if (typeof data?.openSynaplanUrl === 'string') {
				lastOpenSynaplanUrl = data.openSynaplanUrl
			}

			const byId = new Map<string, CloudStatusItem>()
			if (Array.isArray(data?.items)) {
				for (const item of data.items as CloudStatusItem[]) {
					if (item && typeof item.id === 'string') {
						byId.set(item.id, item)
					}
				}
			}
			// Synaplan lists the watches on the asked ids, so an asked id it
			// leaves out has none any more. A failed answer lists none either.
			if (enabled && !unreachable) {
				slice.forEach((entry) => folderWatches.delete(entry.id))
			}
			if (Array.isArray(data?.watches)) {
				for (const watch of data.watches as CloudWatchSummary[]) {
					if (watch && typeof watch.target_id === 'string') {
						folderWatches.set(watch.target_id, watch)
					}
				}
			}

			const expiresAt = Date.now() + CACHE_TTL_MS
			for (const entry of slice) {
				const resolved: ResolvedRowStatus = {
					enabled,
					unreachable,
					item: byId.get(entry.id) ?? null,
					watch: folderWatches.get(entry.id) ?? null,
					glyphs: lastGlyphs,
					openSynaplanUrl: lastOpenSynaplanUrl,
				}
				if (enabled && settled(resolved)) {
					cache.set(cacheKey(entry.id, entry.version), {
						expiresAt,
						value: resolved,
					})
				}
				entry.resolvers.forEach((r) => r(resolved))
			}
		} catch {
			if (asked !== generation) {
				askAgain(slice)
				continue
			}
			for (const entry of slice) {
				entry.resolvers.forEach((r) =>
					r({
						enabled: featureEnabled ?? false,
						unreachable: true,
						item: null,
						watch: null,
						glyphs: lastGlyphs,
						openSynaplanUrl: lastOpenSynaplanUrl,
					}),
				)
			}
		}
	}
}

/**
 * Ask again for rows whose answer was asked for before the cache was
 * invalidated, and hand them the new answer instead.
 *
 * @param entries the rows
 */
function askAgain(entries: PendingItem[]): void {
	for (const entry of entries) {
		requestRowStatus(entry.id, entry.version).then((res) =>
			entry.resolvers.forEach((r) => r(res)),
		)
	}
}
