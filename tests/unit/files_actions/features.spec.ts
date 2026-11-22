import axios from '@nextcloud/axios'
import { Permission } from '@nextcloud/files'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	loadFeatures,
	resetFeatures,
	translationOffered,
} from '@/files_actions/features'
import { translateAction } from '@/files_actions/translateAction'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
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
