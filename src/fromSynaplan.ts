import type { Failure } from './failures'

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { failureFromError } from './failures'

export interface PickedFile {
	id: number
	name: string
	generated: boolean
	created: number
}

export interface AddedFile {
	name: string
	path: string
}

export interface AddResult {
	added: AddedFile[]
	failure: Failure | null
}

/**
 * The folder files are added to, as people read it.
 *
 * @param folder Path of the open folder, "/" for the top
 */
export function folderLabel(folder: string): string {
	const trimmed = folder.replace(/^\/+|\/+$/g, '')
	return trimmed === '' ? t('synaplan_integration', 'Files') : trimmed
}

/**
 * Copy the picked files into the folder one after the other. The first
 * failure stops the rest, so the person sees which ones are still to add.
 *
 * @param files The checked rows
 * @param folder Path of the open folder
 */
export async function addFiles(
	files: PickedFile[],
	folder: string,
): Promise<AddResult> {
	const url = generateUrl('/apps/synaplan_integration/api/v1/from-synaplan')
	const added: AddedFile[] = []
	for (const file of files) {
		try {
			const { data } = await axios.post(url, {
				fileId: file.id,
				name: file.name,
				folder,
			})
			if (!data?.success) {
				return {
					added,
					failure: {
						text: t('synaplan_integration', 'Could not copy this file.'),
					},
				}
			}
			const into = data.folder === 'Files' ? '' : `${data.folder}/`
			added.push({ name: data.name, path: `/${into}${data.name}` })
		} catch (error: unknown) {
			return { added, failure: failureFromError(error) }
		}
	}
	return { added, failure: null }
}
