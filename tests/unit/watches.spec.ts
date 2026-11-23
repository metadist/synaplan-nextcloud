import axios from '@nextcloud/axios'
import { showConfirmation } from '@nextcloud/dialogs'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { resumeWatch } from '@/watches'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), patch: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({
	showConfirmation: vi.fn(),
	showError: vi.fn(),
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path: string) => path,
}))

const get = vi.mocked(axios.get)
const patch = vi.mocked(axios.patch)
const confirm = vi.mocked(showConfirmation)

describe('resuming a watch', () => {
	afterEach(() => {
		vi.resetAllMocks()
	})

	function waiting(data: { waiting: number; ask: boolean }) {
		get.mockResolvedValue({ data: { success: true, ...data } })
		patch.mockResolvedValue({ data: { watch: { id: 5, state: 'active' } } })
	}

	it('asks a task watch and keeps the waiting files when the owner says so', async () => {
		waiting({ waiting: 2, ask: true })
		confirm.mockResolvedValue(true)

		expect(await resumeWatch(5)).toEqual({ id: 5, state: 'active' })

		expect(confirm).toHaveBeenCalledOnce()
		expect(confirm.mock.calls[0][0].text).toBe(
			'Also handle the files that arrived while paused (2)?',
		)
		expect(patch).toHaveBeenCalledWith(
			'/apps/synaplan_integration/api/v1/watches/5',
			{
				state: 'active',
				handleWaiting: true,
			},
		)
	})

	it('resumes with only new files when the owner leaves them out', async () => {
		waiting({ waiting: 2, ask: true })
		confirm.mockResolvedValue(false)

		await resumeWatch(5)

		expect(patch).toHaveBeenCalledWith(
			'/apps/synaplan_integration/api/v1/watches/5',
			{
				state: 'active',
				handleWaiting: false,
			},
		)
	})

	it('does not resume when the owner closes the question', async () => {
		waiting({ waiting: 2, ask: true })
		confirm.mockRejectedValue(new Error('Dialog closed'))

		expect(await resumeWatch(5)).toBeNull()
		expect(patch).not.toHaveBeenCalled()
	})

	it('takes the waiting files of a Keep searchable watch without asking', async () => {
		waiting({ waiting: 2, ask: false })

		await resumeWatch(5)

		expect(confirm).not.toHaveBeenCalled()
		expect(patch).toHaveBeenCalledWith(
			'/apps/synaplan_integration/api/v1/watches/5',
			{
				state: 'active',
				handleWaiting: true,
			},
		)
	})

	it('still resumes when the count cannot be read', async () => {
		get.mockRejectedValue(new Error('Network Error'))
		patch.mockResolvedValue({ data: { watch: { id: 5, state: 'active' } } })

		expect(await resumeWatch(5)).toEqual({ id: 5, state: 'active' })
		expect(confirm).not.toHaveBeenCalled()
	})
})
