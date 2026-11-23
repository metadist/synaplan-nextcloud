import type { IFileAction } from '@nextcloud/files'

import FolderEyeSvg from '@mdi/svg/svg/folder-eye-outline.svg?raw'
import axios, { isAxiosError } from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { Permission } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { failureFromAnswer, failureFromError } from '../failures'
import { extractNodeFromExec, extractNodesFromEnabled } from './compat'
import { readFoldersAllowed } from './features'

/**
 * The sentence for a refusal the server names.
 *
 * @param code the server's error code
 */
function refusal(code: unknown): string | null {
	if (code === 'cap') {
		return t(
			'synaplan_integration',
			'Synaplan may already read as many folders as your administrator allows.',
		)
	}
	if (code === 'ceiling') {
		return t(
			'synaplan_integration',
			'Your administrator lets Synaplan read only the folders you watch.',
		)
	}
	if (code === 'unavailable') {
		return t(
			'synaplan_integration',
			'This Synaplan does not read folders from Nextcloud.',
		)
	}
	return null
}

/**
 * Right-click a folder to let Synaplan read it.
 */
export const readFolderAction: IFileAction = {
	id: 'synaplan:read-folder',
	displayName: () => t('synaplan_integration', 'Let Synaplan read this folder'),
	iconSvgInline: () => FolderEyeSvg,

	enabled(...args: unknown[]) {
		const nodes = extractNodesFromEnabled(...args)
		if (nodes.length !== 1 || !readFoldersAllowed()) {
			return false
		}
		const node = nodes[0]
		if ((node.permissions & Permission.READ) === 0) {
			return false
		}
		return node.type === 'folder' || node.mime === 'httpd/unix-directory'
	},

	async exec(...args: unknown[]) {
		const folder = extractNodeFromExec(...args)
		if (!folder) {
			return null
		}
		try {
			const { data } = await axios.post(
				generateUrl(
					'/apps/synaplan_integration/api/v1/preferences/read-folders',
				),
				{ path: (folder.path || folder.basename).replace(/^\/+/, '') },
			)
			if (data.success) {
				showSuccess(
					t('synaplan_integration', 'Synaplan may read this folder.'),
				)
			} else {
				showError(refusal(data.error) ?? failureFromAnswer(data).text)
			}
		} catch (error: unknown) {
			const code = isAxiosError(error) ? error.response?.data?.error : ''
			showError(refusal(code) ?? failureFromError(error).text)
		}
		return null
	},

	order: 52,
}
