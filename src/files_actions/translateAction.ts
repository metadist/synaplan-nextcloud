import { FileAction, Permission } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { defineAsyncComponent } from 'vue'
import TranslateSvg from '@mdi/svg/svg/translate.svg?raw'

/**
 * MIME prefixes supported for translation.
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

export const translateAction = new FileAction({
	id: 'synaplan:translate',
	displayName: () => t('synaplan_integration', 'Translate with Synaplan'),
	iconSvgInline: () => TranslateSvg,

	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	enabled(arg: any) {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const files = (arg as any).nodes || arg
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
		await spawnDialog(
			defineAsyncComponent(() => import('../components/TranslateModal.vue')),
			{ fileId: file.fileid, fileName: file.basename },
		)
		return null
	},

	order: 51,
})
