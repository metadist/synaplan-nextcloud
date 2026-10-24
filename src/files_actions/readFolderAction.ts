import axios, { isAxiosError } from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { Permission } from '@nextcloud/files'
import type { IFileAction } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import FolderEyeSvg from '@mdi/svg/svg/folder-eye-outline.svg?raw'
import { describeFailure, failureFromError } from '../failures'
import { extractNodesFromEnabled, extractNodeFromExec } from './compat'

/**
 * Right-click a folder to let Synaplan read it.
 */
export const readFolderAction: IFileAction = {
	id: 'synaplan:read-folder',
	displayName: () => t('synaplan_integration', 'Let Synaplan read this folder'),
	iconSvgInline: () => FolderEyeSvg,

	enabled(...args: unknown[]) {
		const nodes = extractNodesFromEnabled(...args)
		if (nodes.length !== 1) return false
		const node = nodes[0]
		if ((node.permissions & Permission.READ) === 0) return false
		return node.type === 'folder' || node.mime === 'httpd/unix-directory'
	},

	async exec(...args: unknown[]) {
		const folder = extractNodeFromExec(...args)
		if (!folder) return null
		try {
			const { data } = await axios.post(
				generateUrl(
					'/apps/synaplan_integration/api/v1/preferences/read-folders',
				),
				{
					fileId: String(folder.fileid),
					path: (folder.path || folder.basename).replace(/^\/+/, ''),
				},
			)
			if (data.success) {
				showSuccess(
					t('synaplan_integration', 'Synaplan may read this folder.'),
				)
			} else if (data.error === 'cap') {
				showError(
					t(
						'synaplan_integration',
						'Synaplan may already read as many folders as your administrator allows.',
					),
				)
			} else {
				showError(describeFailure(data.error, data.message).text)
			}
		} catch (error: unknown) {
			const code = isAxiosError(error) ? error.response?.data?.error : ''
			showError(
				code === 'cap'
					? t(
							'synaplan_integration',
							'Synaplan may already read as many folders as your administrator allows.',
						)
					: failureFromError(error).text,
			)
		}
		return null
	},

	order: 52,
}
