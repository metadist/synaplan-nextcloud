import { FileAction, Permission } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { defineAsyncComponent } from 'vue'
import TextBoxSearchOutlineSvg from '@mdi/svg/svg/text-box-search-outline.svg?raw'

/**
 * MIME types supported for summarization.
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

export const summarizeAction = new FileAction({
	id: 'synaplan:summarize',
	displayName: () => t('synaplan_integration', 'Summarize with Synaplan'),
	iconSvgInline: () => TextBoxSearchOutlineSvg,

	enabled(files) {
		if (files.length !== 1) {
			return false
		}
		const node = files[0]
		if ((node.permissions & Permission.READ) === 0) {
			return false
		}
		const mime = node.mime || ''
		return SUPPORTED_MIMES.some((m) => mime.startsWith(m))
	},

	async exec(file) {
		await spawnDialog(
			defineAsyncComponent(() => import('../components/SummaryModal.vue')),
			{ fileId: file.fileid, fileName: file.basename },
		)
		return null
	},

	order: 50,
})
