import type { IFileAction } from '@nextcloud/files'

import TranslateSvg from '@mdi/svg/svg/translate.svg?raw'
import { Permission } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { defineAsyncComponent } from 'vue'
import { extractNodeFromExec, extractNodesFromEnabled } from './compat'
import { isExtractable } from './extractable'
import { translationOffered } from './features'

export const translateAction: IFileAction = {
	id: 'synaplan:translate',
	displayName: () => t('synaplan_integration', 'Translate with Synaplan'),
	iconSvgInline: () => TranslateSvg,

	enabled(...args: unknown[]) {
		if (!translationOffered()) {
			return false
		}
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
			defineAsyncComponent(() => import('../components/TranslateModal.vue')),
			{ fileId: file.fileid, fileName: file.basename },
		)
		return null
	},

	order: 51,
}
