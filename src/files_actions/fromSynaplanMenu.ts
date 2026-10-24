import CloudDownloadOutlineSvg from '@mdi/svg/svg/cloud-download-outline.svg?raw'
import {
	addNewFileMenuEntry,
	NewMenuEntryCategory,
	Permission,
} from '@nextcloud/files'
import type { IFolder } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { defineAsyncComponent } from 'vue'

/**
 * New → From Synaplan. The file is written into the folder that is open.
 */
export function registerFromSynaplanMenu(): void {
	addNewFileMenuEntry({
		id: 'synaplan:from-synaplan',
		displayName: t('synaplan_integration', 'From Synaplan'),
		iconSvgInline: CloudDownloadOutlineSvg,
		category: NewMenuEntryCategory.Other,
		order: 50,
		enabled(context: IFolder): boolean {
			return (context.permissions & Permission.CREATE) !== 0
		},
		handler(context: IFolder) {
			spawnDialog(
				defineAsyncComponent(
					() => import('../components/FromSynaplanModal.vue'),
				),
				{ folder: context.path },
			)
		},
	})
}
