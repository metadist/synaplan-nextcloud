import { FileAction, Permission } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { defineAsyncComponent } from 'vue'
import DatabasePlusOutlineSvg from '@mdi/svg/svg/database-plus-outline.svg?raw'

/**
 * MIME types supported for knowledge base upload.
 *
 * Broader than summarize/translate because Synaplan uses Tika
 * for server-side text extraction from binary formats.
 */
const SUPPORTED_MIMES = [
	// Text-based (direct read)
	'text/plain',
	'text/markdown',
	'text/csv',
	'text/html',
	'text/xml',
	'text/rtf',
	'application/json',
	'application/xml',
	'application/rtf',
	// Documents (Tika extraction)
	'application/pdf',
	'application/msword',
	'application/vnd.openxmlformats-officedocument.wordprocessingml',
	'application/vnd.oasis.opendocument.text',
	// Spreadsheets
	'application/vnd.ms-excel',
	'application/vnd.openxmlformats-officedocument.spreadsheetml',
	'application/vnd.oasis.opendocument.spreadsheet',
	// Presentations
	'application/vnd.ms-powerpoint',
	'application/vnd.openxmlformats-officedocument.presentationml',
	'application/vnd.oasis.opendocument.presentation',
]

export const chatAction = new FileAction({
	id: 'synaplan:knowledge',
	displayName: () => t('synaplan_integration', 'Add to AI Knowledge'),
	iconSvgInline: () => DatabasePlusOutlineSvg,

	enabled({ nodes }) {
		if (nodes.length !== 1) {
			return false
		}
		const node = nodes[0]
		if ((node.permissions & Permission.READ) === 0) {
			return false
		}
		const mime = node.mime || ''
		return SUPPORTED_MIMES.some((m) => mime.startsWith(m))
	},

	async exec({ nodes }) {
		const node = nodes[0]
		await spawnDialog(
			defineAsyncComponent(() => import('../components/KnowledgeModal.vue')),
			{ fileId: node.fileid, fileName: node.basename },
		)
		return null
	},

	order: 52,
})
