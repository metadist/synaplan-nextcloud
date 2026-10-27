import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import type { CloudFileStateCode, GlyphOverrides } from './glyphs'

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
const BATCH_WINDOW_MS = 50
const MAX_BATCH = 500

const cache = new Map<string, CacheEntry>()
const folderWatches = new Map<string, CloudWatchSummary>()
let featureEnabled: boolean | null = null
let lastGlyphs: GlyphOverrides = {}
let lastOpenSynaplanUrl = ''
const listeners = new Set<() => void>()

interface PendingItem {
	id: string
	version: string
	resolvers: Array<(res: ResolvedRowStatus) => void>
}

const pending = new Map<string, PendingItem>()
let flushTimer: ReturnType<typeof setTimeout> | null = null

/**
 *
 * @param id
 * @param version
 */
function cacheKey(id: string, version: string): string {
	return `${id}:${version}`
}

/**
 * Subscribe to status cache invalidations (after Make searchable or Remove).
 *
 * @param {() => void} fn Listener callback
 */
export function onCloudStatusInvalidated(fn: () => void): () => void {
	listeners.add(fn)
	return () => listeners.delete(fn)
}

/**
 * Clear the 60-second client cache after a knowledge change.
 */
export function invalidateCloudStatusCache(): void {
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
 * @param {string} targetId Nextcloud fileid as string
 */
export function watchForTarget(targetId: string): CloudWatchSummary | null {
	return folderWatches.get(targetId) ?? null
}

/**
 * Request the searchable state for one row in the current folder view.
 * Collects all visible rows rendered within 50 ms into one batched POST.
 *
 * @param {string} id Nextcloud fileid as string
 * @param {string} version Nextcloud etag
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
		try {
			const { data } = await axios.post(`${baseUrl}/api/v1/cloud/status`, {
				items: slice.map((e) => ({ id: e.id, version: e.version })),
			})
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
				if (enabled && !unreachable) {
					cache.set(cacheKey(entry.id, entry.version), {
						expiresAt,
						value: resolved,
					})
				}
				entry.resolvers.forEach((r) => r(resolved))
			}
		} catch {
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
