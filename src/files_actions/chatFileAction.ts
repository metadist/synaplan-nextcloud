import { Permission } from '@nextcloud/files'
import type { IFileAction } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { defineAsyncComponent } from 'vue'
import MessageTextOutlineSvg from '@mdi/svg/svg/message-text-outline.svg?raw'
import { extractNodesFromEnabled, extractNodeFromExec } from './compat'

// The chat endpoint only reads text content as context for these mime types
// (see lib/Controller/ChatController::getFileInfo), so the action is limited to
// them to avoid offering "chat about this file" where no content can be read.
const SUPPORTED_MIMES = ['text/', 'application/json', 'application/xml']

export const chatFileAction: IFileAction = {
	id: 'synaplan:chat',
	displayName: () => t('synaplan_integration', 'Chat about this file'),
	iconSvgInline: () => MessageTextOutlineSvg,

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
			defineAsyncComponent(() => import('../components/ChatModal.vue')),
			{ fileId: file.fileid, fileName: file.basename },
		)
		return null
	},

	order: 51,
}
