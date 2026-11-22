import type { IFileAction } from '@nextcloud/files'

import EyePlusOutlineSvg from '@mdi/svg/svg/eye-plus-outline.svg?raw'
import { Permission } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { defineAsyncComponent } from 'vue'
import { extractNodeFromExec, extractNodesFromEnabled } from './compat'

/**
 * Whether a node is a folder.
 *
 * @param node the file list node
 * @param node.type its type
 * @param node.mime its MIME type
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
		// A share's root always carries UPDATE because its mount point can be
		// renamed; adding files is what editing a folder means.
		const edit = isFolder(nodes[0]) ? Permission.CREATE : Permission.UPDATE
		const needed = Permission.READ | edit
		return (nodes[0].permissions & needed) === needed
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
