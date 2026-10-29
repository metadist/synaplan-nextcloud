import { Permission } from '@nextcloud/files'
import type { IFileAction } from '@nextcloud/files'
import { describe, expect, it } from 'vitest'

import { chatAction } from '@/files_actions/chatAction'
import { chatFileAction } from '@/files_actions/chatFileAction'
import { summarizeAction } from '@/files_actions/summarizeAction'
import { translateAction } from '@/files_actions/translateAction'
import { watchAction } from '@/files_actions/watchAction'

const PDF = 'application/pdf'
const DOCX =
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
const XLS = 'application/vnd.ms-excel'
const TEXT = 'text/plain'
const PNG = 'image/png'

const cases: Array<[string, IFileAction, string[], string[]]> = [
	['summarize', summarizeAction, [PDF, DOCX, TEXT], [XLS, PNG]],
	['translate', translateAction, [PDF, DOCX, TEXT], [XLS, PNG]],
	['make searchable', chatAction, [PDF, DOCX, XLS, TEXT], [PNG]],
	['chat about this file', chatFileAction, [TEXT, 'application/json'], [PDF, PNG]],
]

const file = (mime: string, permissions = Permission.READ) => ({
	fileid: 7,
	basename: 'file',
	mime,
	permissions,
})

// enabled() is typed for @nextcloud/files 4; the compat layer also accepts
// the 3.x call shape (nodes, view), so the tests call it untyped.
const enabled = (action: IFileAction, ...args: unknown[]): boolean =>
	(action.enabled as (...a: unknown[]) => boolean)(...args)

describe.each(cases)('%s', (_name, action, shown, hidden) => {
	it.each(shown)('is offered for %s, in both call shapes', (mime) => {
		expect(enabled(action, { nodes: [file(mime)] })).toBe(true)
		expect(enabled(action, [file(mime)], {})).toBe(true)
	})

	it.each(hidden)('is not offered for %s', (mime) => {
		expect(enabled(action, { nodes: [file(mime)] })).toBe(false)
	})

	it('needs read permission', () => {
		expect(enabled(action, { nodes: [file(shown[0], Permission.NONE)] })).toBe(
			false,
		)
	})

	it('works on exactly one file', () => {
		expect(enabled(action, { nodes: [file(shown[0]), file(shown[0])] })).toBe(
			false,
		)
		expect(enabled(action, { nodes: [] })).toBe(false)
	})
})

describe('watch', () => {
	const folder = {
		fileid: 8,
		basename: 'Invoices',
		type: 'folder',
		mime: 'httpd/unix-directory',
		permissions: Permission.READ,
	}

	it('is offered for a readable file and a folder, in both call shapes', () => {
		expect(enabled(watchAction, { nodes: [file(PNG)] })).toBe(true)
		expect(enabled(watchAction, [file(PNG)], {})).toBe(true)
		expect(enabled(watchAction, { nodes: [folder] })).toBe(true)
	})

	it('needs read permission and exactly one item', () => {
		expect(enabled(watchAction, { nodes: [file(PNG, Permission.NONE)] })).toBe(
			false,
		)
		expect(enabled(watchAction, { nodes: [file(PNG), file(PDF)] })).toBe(false)
		expect(enabled(watchAction, { nodes: [] })).toBe(false)
	})
})
