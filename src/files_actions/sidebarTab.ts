/* eslint-disable padded-blocks */
import { createApp, ref, type App } from 'vue'
import { getSidebar, registerSidebarTab, type INode } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { BIRD_SVG } from '../cloud_state/glyphs'
import { isCloudStatusEnabled } from '../cloud_state/statusBatcher'
import FilesSidebarTab from '../components/FilesSidebarTab.vue'
import { SIDEBAR_TAB_ID } from './birdRowAction'

const TAG_NAME = 'synaplan_integration-files-sidebar-tab'

class SynaplanSidebarElement extends HTMLElement {
	private app: App | null = null
	private currentNode: INode | null = null
	private fileIdRef = ref<number>(0)
	private fileNameRef = ref<string>('')

	set node(value: INode | undefined) {
		this.currentNode = value ?? null
		const record = (value ?? {}) as unknown as Record<string, unknown>
		const rawId = record.fileid ?? record.id ?? 0
		this.fileIdRef.value = Number(rawId) || 0
		this.fileNameRef.value = String(record.basename ?? record.displayname ?? '')
	}

	get node(): INode | undefined {
		return this.currentNode ?? undefined
	}

	connectedCallback(): void {
		this.style.display = 'block'
		if (this.app) {
			return
		}
		this.app = createApp(FilesSidebarTab, {
			fileIdRef: this.fileIdRef,
			fileNameRef: this.fileNameRef,
		})
		this.app.mount(this)
	}

	disconnectedCallback(): void {
		if (this.app) {
			this.app.unmount()
			this.app = null
		}
	}
}

/**
 *
 */
function ensureCustomElementDefined(): void {
	if (typeof customElements !== 'undefined' && !customElements.get(TAG_NAME)) {
		customElements.define(TAG_NAME, SynaplanSidebarElement)
	}
}

/**
 * Register the "Synaplan" tab in the Nextcloud Files sidebar.
 */
export function registerSynaplanSidebarTab(): void {
	ensureCustomElementDefined()
	const tab = {
		id: SIDEBAR_TAB_ID,
		displayName: t('synaplan_integration', 'Synaplan'),
		iconSvgInline: BIRD_SVG,
		order: 50,
		tagName: TAG_NAME,
		enabled: () => isCloudStatusEnabled() !== false,
		onInit: async () => {
			ensureCustomElementDefined()
		},
	}

	try {
		registerSidebarTab(tab)
	} catch {
		try {
			getSidebar()?.registerTab(tab)
		} catch {
			// Sidebar API unavailable outside Files.
		}
	}
}
