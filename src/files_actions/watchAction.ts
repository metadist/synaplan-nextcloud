import type { IFileAction } from '@nextcloud/files'

import EyePlusOutlineSvg from '@mdi/svg/svg/eye-plus-outline.svg?raw'
import { Permission } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { defineAsyncComponent } from 'vue'
import { extractNodeFromExec, extractNodesFromEnabled } from './compat'

/**
 *
 * @param node
 * @param node.type
 * @param node.mime
 */
function isFolder(node: { type?: string; mime?: string }): boolean {
	return node.type === 'folder' || node.mime === 'httpd/unix-directory'
}

export const watchAction: IFileAction = {
	id: 'synaplan:watch',
	displayName: () => t('synaplan_integration', 'Watch with Synaplan…'),
	iconSvgInline: () => EyePlusOutlineSvg,

	enabled(...args: unknown[]) {
		const nodes = extractNodesFromEnabled(...args)
		if (nodes.length !== 1) {
			return false
		}
		return (nodes[0].permissions & Permission.READ) !== 0
	},

	async exec(...args: unknown[]) {
		const node = extractNodeFromExec(...args)
		if (!node) {
			return null
		}
		await spawnDialog(
			defineAsyncComponent(() => import('../components/WatchModal.vue')),
			{
				fileId: String(node.fileid),
				name: node.basename,
				kind: isFolder(node) ? 'folder' : 'file',
			},
		)
		return null
	},

	order: 53,
}
