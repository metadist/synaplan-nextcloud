import type { IFileAction } from '@nextcloud/files'

import TextBoxSearchOutlineSvg from '@mdi/svg/svg/text-box-search-outline.svg?raw'
import { Permission } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { defineAsyncComponent } from 'vue'
import { extractNodeFromExec, extractNodesFromEnabled } from './compat'
import { isExtractable } from './extractable'

export const summarizeAction: IFileAction = {
	id: 'synaplan:summarize',
	displayName: () => t('synaplan_integration', 'Summarize with Synaplan'),
	iconSvgInline: () => TextBoxSearchOutlineSvg,

	enabled(...args: unknown[]) {
		const nodes = extractNodesFromEnabled(...args)
		if (nodes.length !== 1) {
			return false
		}
		const node = nodes[0]
		if ((node.permissions & Permission.READ) === 0) {
			return false
		}
		return isExtractable(node.mime ?? '')
	},

	async exec(...args: unknown[]) {
		const file = extractNodeFromExec(...args)
		if (!file) {
			return null
		}
		await spawnDialog(
			defineAsyncComponent(() => import('../components/SummaryModal.vue')),
			{ fileId: file.fileid, fileName: file.basename },
		)
		return null
	},

	order: 50,
}
