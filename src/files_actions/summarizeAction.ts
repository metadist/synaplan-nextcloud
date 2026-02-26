import { FileAction, Permission } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { defineAsyncComponent } from 'vue'
import TextBoxSearchOutlineSvg from '@mdi/svg/svg/text-box-search-outline.svg?raw'

/**
 * MIME prefixes supported for summarization.
 * Uses startsWith matching so OOXML sub-types (.document, .sheet, etc.) are covered.
 */
const SUPPORTED_MIMES = [
	'text/',
	'application/pdf',
	'application/json',
	'application/xml',
	'application/rtf',
	// MS Office legacy
	'application/msword',
	// OOXML (Word, Excel, PowerPoint)
	'application/vnd.openxmlformats-officedocument',
	// OpenDocument (Writer, Calc, Impress, Draw)
	'application/vnd.oasis.opendocument',
]

export const summarizeAction = new FileAction({
	id: 'synaplan:summarize',
	displayName: () => t('synaplan_integration', 'Summarize with Synaplan'),
	iconSvgInline: () => TextBoxSearchOutlineSvg,

	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	enabled(arg: any) {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const files = (arg as any).nodes || arg
		console.info('[Synaplan] summarize enabled check:', files)
		if (!files || files.length !== 1) return false
		const node = files[0]
		if ((node.permissions & Permission.READ) === 0) return false
		const mime = (node.mime ?? '') as string
		return SUPPORTED_MIMES.some((prefix) => mime.startsWith(prefix))
	},

	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	async exec(arg: any) {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const file = ((arg as any).nodes && (arg as any).nodes[0]) || arg
		console.info('[Synaplan] summarize exec:', file)
		await spawnDialog(
			defineAsyncComponent(() => import('../components/SummaryModal.vue')),
			{ fileId: file.fileid, fileName: file.basename },
		)
		return null
	},

	order: 50,
})
