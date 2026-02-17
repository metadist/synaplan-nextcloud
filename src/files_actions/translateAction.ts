import { FileAction, Permission } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { defineAsyncComponent } from 'vue'
import TranslateSvg from '@mdi/svg/svg/translate.svg?raw'

/**
 * MIME types supported for translation.
 *
 * Text files are read directly; binary document formats are uploaded
 * to Synaplan first for Tika-based text extraction.
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
	// Documents (Tika extraction via Synaplan upload)
	'application/pdf',
	'application/msword',
	'application/vnd.openxmlformats-officedocument.wordprocessingml',
	'application/vnd.oasis.opendocument.text',
]

export const translateAction = new FileAction({
	id: 'synaplan:translate',
	displayName: () => t('synaplan_integration', 'Translate with Synaplan'),
	iconSvgInline: () => TranslateSvg,

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
			defineAsyncComponent(() => import('../components/TranslateModal.vue')),
			{ fileId: node.fileid, fileName: node.basename },
		)
		return null
	},

	order: 51,
})
