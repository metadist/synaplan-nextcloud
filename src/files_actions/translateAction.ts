import { Permission } from '@nextcloud/files'
import type { IFileAction } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { defineAsyncComponent } from 'vue'
import TranslateSvg from '@mdi/svg/svg/translate.svg?raw'
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

export const translateAction: IFileAction = {
	id: 'synaplan:translate',
	displayName: () => t('synaplan_integration', 'Translate with Synaplan'),
	iconSvgInline: () => TranslateSvg,

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
			defineAsyncComponent(() => import('../components/TranslateModal.vue')),
			{ fileId: file.fileid, fileName: file.basename },
		)
		return null
	},

	order: 51,
}
