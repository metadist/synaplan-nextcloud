import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { addFiles, folderLabel } from '@/fromSynaplan'

vi.mock('@nextcloud/axios', async (importOriginal) => ({
	...(await importOriginal<object>()),
	default: { post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path: string) => path,
}))

const post = vi.mocked(axios.post)

const chart = { id: 9, name: 'chart.png', generated: true, created: 1 }
const plan = { id: 8, name: 'Q3 plan.docx', generated: true, created: 1 }
const minutes = { id: 7, name: 'minutes.md', generated: false, created: 1 }

describe('addFiles', () => {
	beforeEach(() => {
		post.mockReset()
	})

	it('adds every checked file to the open folder', async () => {
		post.mockImplementation(async (_url, body) => ({
			data: {
				success: true,
				name: (body as { name: string }).name,
				folder: 'Projects/Q3',
			},
		}))

		const result = await addFiles([chart, plan], '/Projects/Q3')

		expect(post).toHaveBeenCalledTimes(2)
		expect(post).toHaveBeenNthCalledWith(
			1,
			'/apps/synaplan_integration/api/v1/from-synaplan',
			{ fileId: 9, name: 'chart.png', folder: '/Projects/Q3' },
		)
		expect(result).toEqual({
			added: [
				{ name: 'chart.png', path: '/Projects/Q3/chart.png' },
				{ name: 'Q3 plan.docx', path: '/Projects/Q3/Q3 plan.docx' },
			],
			failure: null,
		})
	})

	it('stops at the first file that fails and says why', async () => {
		post.mockResolvedValueOnce({
			data: { success: true, name: 'chart.png', folder: 'Files' },
		}).mockRejectedValueOnce({
			isAxiosError: true,
			response: { status: 409, data: { error: 'storage_full' } },
		})

		const result = await addFiles([chart, plan, minutes], '/')

		expect(post).toHaveBeenCalledTimes(2)
		expect(result.added).toEqual([{ name: 'chart.png', path: '/chart.png' }])
		expect(result.failure?.text).toBe(
			'This file does not fit into your storage. Make room and try again.',
		)
	})

	it('adds into a folder named Files, not the top folder', async () => {
		post.mockResolvedValueOnce({
			data: { success: true, name: 'cat.png', folder: 'Files' },
		})

		const result = await addFiles([chart], '/Files')

		expect(result.added).toEqual([{ name: 'cat.png', path: '/Files/cat.png' }])
	})
})

describe('folderLabel', () => {
	it('names the top folder Files and others by their path', () => {
		expect(folderLabel('/')).toBe('Files')
		expect(folderLabel('/Projects/Q3')).toBe('Projects/Q3')
	})
})
