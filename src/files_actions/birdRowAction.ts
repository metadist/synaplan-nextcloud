import {
	DefaultType,
	getSidebar,
	registerFileListHeader,
	type IFileAction,
	type IFolder,
	type INode,
} from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import {
	badgeForState,
	glyphMarkup,
	labelForState,
	type CloudFileStateCode,
} from '../cloud_state/glyphs'
import {
	isCloudStatusEnabled,
	onCloudStatusInvalidated,
	requestRowStatus,
	watchForTarget,
} from '../cloud_state/statusBatcher'
import { extractNodeFromExec, extractNodesFromEnabled } from './compat'

export const SIDEBAR_TAB_ID = 'synaplan_integration'

function extractNodeId(node: Record<string, unknown>): string {
	const raw = node.fileid ?? node.id
	return raw !== undefined && raw !== null ? String(raw) : ''
}

function extractNodeVersion(node: Record<string, unknown>): string {
	const attrs = (node.attributes ?? {}) as Record<string, unknown>
	const data = (node._data ?? {}) as Record<string, unknown>
	const dataAttrs = (data.attributes ?? {}) as Record<string, unknown>
	const raw =
		attrs.etag
		?? dataAttrs.etag
		?? attrs.getetag
		?? dataAttrs.getetag
		?? node.etag
		?? ''
	return String(raw).replace(/^"|"$/g, '')
}

function openSidebarFor(node: INode): void {
	try {
		const sidebar = getSidebar()
		if (sidebar?.available) {
			sidebar.open(node, SIDEBAR_TAB_ID)
		}
	} catch {
		// Sidebar not mounted in this view.
	}
}

export const birdRowAction: IFileAction = {
	id: 'synaplan-bird-state',
	displayName: () => 'Synaplan',
	iconSvgInline: () => '',
	default: DefaultType.HIDDEN,
	order: -10,

	enabled: (...args: unknown[]) =>
		extractNodesFromEnabled(...args).length === 1
		&& isCloudStatusEnabled() !== false,

	renderInline: async (...args: unknown[]): Promise<HTMLElement | null> => {
		const node = extractNodeFromExec(...args) as
			(INode & Record<string, unknown>) | null
		if (!node) {
			return null
		}
		const id = extractNodeId(node)
		if (!id) {
			return null
		}

		const slot = document.createElement('span')
		slot.className = 'synaplan-row-bird-slot'
		slot.style.display = 'none'

		const renderIntoSlot = async (): Promise<void> => {
			const version = extractNodeVersion(node)
			const resolved = await requestRowStatus(id, version)
			if (!resolved.enabled || resolved.unreachable) {
				slot.innerHTML = ''
				slot.style.display = 'none'
				return
			}

			const state: CloudFileStateCode = resolved.item?.state ?? 'none'
			const watch = resolved.watch
			if (state === 'none' && !watch) {
				slot.innerHTML = ''
				slot.style.display = 'none'
				return
			}

			const folderName =
				resolved.item?.knowledge_folder?.name
				?? resolved.item?.knowledge_folder?.key
				?? null
			const watchState = watch?.state ?? null
			const label = labelForState(
				state,
				folderName,
				watch?.sentence ?? null,
				watchState,
			)
			const badge = badgeForState(state, watchState)
			const eyes = Boolean(watch)

			const el = document.createElement('span')
			el.className = 'synaplan-row-bird'
			el.setAttribute('data-testid', 'synaplan-row-bird')
			el.setAttribute('data-synaplan-state', state)
			el.setAttribute('role', 'button')
			el.setAttribute('tabindex', '0')
			el.setAttribute('title', label)
			el.setAttribute('aria-label', label)
			el.style.cssText = [
				'position:relative',
				'display:inline-flex',
				'align-items:center',
				'justify-content:center',
				'width:20px',
				'height:20px',
				'margin-right:6px',
				'cursor:pointer',
				'color:' + colorForState(state, watchState),
			].join(';')
			el.innerHTML = glyphMarkup({
				eyes,
				badge,
				size: 16,
				overrides: resolved.glyphs,
			})

			const onActivate = (ev: Event) => {
				ev.stopPropagation()
				ev.preventDefault()
				openSidebarFor(node)
			}
			el.addEventListener('click', onActivate)
			el.addEventListener('keydown', (ev: KeyboardEvent) => {
				if (ev.key === 'Enter' || ev.key === ' ') {
					onActivate(ev)
				}
			})

			slot.innerHTML = ''
			slot.style.display = 'inline-flex'
			slot.appendChild(el)
		}

		await renderIntoSlot()
		const unsubscribe = onCloudStatusInvalidated(() => {
			if (!slot.isConnected) {
				unsubscribe()
				return
			}
			renderIntoSlot()
		})

		return slot
	},

	exec: async (...args: unknown[]) => {
		const node = extractNodeFromExec(...args) as INode | null
		if (node) {
			openSidebarFor(node)
		}
		return null
	},
}

function colorForState(
	state: CloudFileStateCode,
	watchState: string | null,
): string {
	if (state === 'failed' || watchState === 'attention') {
		return 'light-dark(#dc2626, #f87171)'
	}
	if (state === 'changed' || state === 'updating' || watchState === 'paused') {
		return 'light-dark(#b45309, #fbbf24)'
	}
	if (state === 'adding') {
		return 'light-dark(#0284c7, #38bdf8)'
	}
	return 'light-dark(#059669, #34d399)'
}

/**
 * Register the folder list header that explains when the open folder is watched.
 */
export function registerWatchedFolderHeader(): void {
	let currentEl: HTMLElement | null = null
	let currentFolder: IFolder | null = null

	const updateHeader = async (folder: IFolder | null) => {
		if (!currentEl) {
			return
		}
		currentEl.innerHTML = ''
		if (!folder || isCloudStatusEnabled() === false) {
			currentEl.style.display = 'none'
			return
		}
		const folderNode = folder as unknown as Record<string, unknown>
		const folderId = extractNodeId(folderNode)
		if (!folderId) {
			currentEl.style.display = 'none'
			return
		}
		const resolved = await requestRowStatus(
			folderId,
			extractNodeVersion(folderNode),
		)
		if (!currentEl || currentFolder !== folder) {
			return
		}
		const watch = resolved.watch ?? watchForTarget(folderId)
		if (!watch) {
			currentEl.style.display = 'none'
			return
		}

		currentEl.style.cssText = [
			'display:flex',
			'align-items:center',
			'gap:8px',
			'padding:8px 16px',
			'font-size:0.9em',
			'color:var(--color-main-text, #222)',
			'background:var(--color-background-dark, rgba(0, 0, 0, 0.04))',
			'border-bottom:1px solid var(--color-border, rgba(0, 0, 0, 0.08))',
		].join(';')

		const icon = document.createElement('span')
		icon.style.cssText =
			'position:relative;display:inline-flex;align-items:center;color:light-dark(#059669, #34d399);'
		icon.innerHTML = glyphMarkup({
			eyes: true,
			badge: badgeForState('none', watch.state),
			size: 16,
		})

		const text = document.createElement('span')
		text.textContent = watch.sentence

		const details = document.createElement('button')
		details.type = 'button'
		details.textContent = t('synaplan_integration', 'Details')
		details.style.cssText =
			'margin-left:auto;background:transparent;border:none;color:var(--color-primary-element, #0082c9);font-weight:600;cursor:pointer;'
		details.addEventListener('click', () => {
			openSidebarFor(folder as unknown as INode)
		})

		currentEl.appendChild(icon)
		currentEl.appendChild(text)
		currentEl.appendChild(details)
	}

	try {
		registerFileListHeader({
			id: 'synaplan-watched-folder-header',
			order: 10,
			enabled: () => isCloudStatusEnabled() !== false,
			render(el: HTMLElement, folder: IFolder) {
				currentEl = el
				currentFolder = folder
				updateHeader(folder)
			},
			updated(folder: IFolder) {
				currentFolder = folder
				updateHeader(folder)
			},
		})
		onCloudStatusInvalidated(() => updateHeader(currentFolder))
	} catch {
		// Older Files builds without registerFileListHeader ignore the header.
	}
}
