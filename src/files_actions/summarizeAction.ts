import { Permission } from '@nextcloud/files'
import type { IFileAction } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { defineAsyncComponent } from 'vue'
import TextBoxSearchOutlineSvg from '@mdi/svg/svg/text-box-search-outline.svg?raw'
import { extractNodesFromEnabled, extractNodeFromExec } from './compat'

const SUPPORTED_MIMES = [
	'text/',
	'application/pdf',
	'application/json',
	'application/xml',
	'application/rtf',
	'application/msword',
	'application/vnd.openxmlformats-officedocument',
	'application/vnd.oasis.opendocument',
]

export const summarizeAction: IFileAction = {
	id: 'synaplan:summarize',
	displayName: () => t('synaplan_integration', 'Summarize with Synaplan'),
	iconSvgInline: () => TextBoxSearchOutlineSvg,

	enabled(...args: unknown[]) {
		const nodes = extractNodesFromEnabled(...args)
		if (nodes.length !== 1) return false
		const node = nodes[0]
		if ((node.permissions & Permission.READ) === 0) return false
		const mime = node.mime ?? ''
		return SUPPORTED_MIMES.some((prefix) => mime.startsWith(prefix))
	},

	async exec(...args: unknown[]) {
		const file = extractNodeFromExec(...args)
		if (!file) return null
		await spawnDialog(
			defineAsyncComponent(() => import('../components/SummaryModal.vue')),
			{ fileId: file.fileid, fileName: file.basename },
		)
		return null
	},

	order: 50,
}
