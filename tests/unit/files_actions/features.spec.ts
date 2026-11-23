import axios from '@nextcloud/axios'
import { Permission } from '@nextcloud/files'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	changesFollowed,
	loadFeatures,
	readFoldersAllowed,
	resetFeatures,
	translationOffered,
	watchingAllowed,
	watchSharedAllowed,
} from '@/files_actions/features'
import { readFolderAction } from '@/files_actions/readFolderAction'
import { translateAction } from '@/files_actions/translateAction'
import { watchAction } from '@/files_actions/watchAction'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path: string) => path,
}))

const get = vi.mocked(axios.get)

function offered(): boolean {
	const pdf = {
		fileid: 7,
		basename: 'file.pdf',
		mime: 'application/pdf',
		permissions: Permission.READ,
	}
	return (translateAction.enabled as (...a: unknown[]) => boolean)({
		nodes: [pdf],
	})
}

afterEach(() => {
	resetFeatures()
	get.mockReset()
})

describe('translate', () => {
	it('is offered while Synaplan has not answered', () => {
		expect(translationOffered()).toBe(true)
		expect(offered()).toBe(true)
	})

	it('is hidden when Synaplan does not list translation', async () => {
		get.mockResolvedValue({ data: { translation: false } })
		await loadFeatures()

		expect(get).toHaveBeenCalledWith(
			'/apps/synaplan_integration/api/v1/features',
		)
		expect(offered()).toBe(false)
	})

	it('stays offered when Synaplan lists it or cannot be asked', async () => {
		get.mockResolvedValue({ data: { translation: true } })
		await loadFeatures()
		expect(offered()).toBe(true)

		resetFeatures()
		get.mockRejectedValue(new Error('Network Error'))
		await loadFeatures()
		expect(offered()).toBe(true)
	})

	it('asks Synaplan only once', async () => {
		get.mockResolvedValue({ data: { translation: true } })
		await Promise.all([loadFeatures(), loadFeatures()])
		expect(get).toHaveBeenCalledTimes(1)
	})
})

describe('let Synaplan read this folder', () => {
	const folder = {
		fileid: 9,
		basename: 'Invoices',
		type: 'folder',
		permissions: Permission.READ,
	}

	/**
	 * Whether the action is offered for one folder.
	 */
	function readOffered(): boolean {
		return (readFolderAction.enabled as (...a: unknown[]) => boolean)({
			nodes: [folder],
		})
	}

	it('is offered while the administrator lets people allow folders', async () => {
		get.mockResolvedValue({ data: { translation: true, readFolders: true } })
		await loadFeatures()

		expect(readOffered()).toBe(true)
	})

	it('is hidden while Synaplan may read only watched folders', async () => {
		get.mockResolvedValue({ data: { translation: true, readFolders: false } })
		await loadFeatures()

		expect(readFoldersAllowed()).toBe(false)
		expect(readOffered()).toBe(false)
	})
})

describe('watch on a shared folder', () => {
	const folder = {
		fileid: 8,
		basename: 'Incoming',
		type: 'folder',
		permissions: Permission.READ | Permission.CREATE,
	}

	/**
	 * Whether the watch action is offered for one folder.
	 *
	 * @param attributes the folder's Files attributes
	 */
	function watchOffered(attributes: Record<string, unknown>): boolean {
		return (watchAction.enabled as (...a: unknown[]) => boolean)({
			nodes: [{ ...folder, attributes }],
		})
	}

	it('is offered while the administrator allows it', async () => {
		get.mockResolvedValue({ data: { translation: true, watchShared: true } })
		await loadFeatures()

		expect(watchSharedAllowed()).toBe(true)
		expect(watchOffered({ 'mount-type': 'shared-root' })).toBe(true)
	})

	it('is hidden everywhere while the administrator turned off watching folders', async () => {
		get.mockResolvedValue({
			data: { translation: true, watches: false, watchShared: true },
		})
		await loadFeatures()

		expect(watchingAllowed()).toBe(false)
		expect(watchOffered({ 'mount-type': '' })).toBe(false)
		expect(watchOffered({ 'mount-type': 'shared-root' })).toBe(false)
	})

	it('is hidden for a shared folder when the administrator turned it off', async () => {
		get.mockResolvedValue({ data: { translation: true, watchShared: false } })
		await loadFeatures()

		expect(watchOffered({ 'mount-type': 'shared-root' })).toBe(false)
		expect(watchOffered({ 'mount-type': 'shared' })).toBe(false)
		expect(watchOffered({ 'share-types': { 'share-type': 0 } })).toBe(false)
		expect(watchOffered({ 'share-types': [3] })).toBe(false)
		expect(watchOffered({ 'share-types': '' })).toBe(true)
		expect(watchOffered({ 'share-types': [] })).toBe(true)
		expect(watchOffered({ 'mount-type': '' })).toBe(true)
	})
})

describe('keep it up to date', () => {
	it('is not offered while Synaplan has not answered or cannot be asked', async () => {
		expect(changesFollowed()).toBe(false)

		get.mockRejectedValue(new Error('offline'))
		await loadFeatures()
		expect(changesFollowed()).toBe(false)
	})

	it('is offered only where Synaplan hears when a file changes', async () => {
		get.mockResolvedValue({ data: { followsChanges: true } })
		await loadFeatures()
		expect(changesFollowed()).toBe(true)

		resetFeatures()
		get.mockResolvedValue({ data: { translation: true } })
		await loadFeatures()
		expect(changesFollowed()).toBe(false)
	})
})
