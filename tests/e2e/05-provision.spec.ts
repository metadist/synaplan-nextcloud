import { expect, test } from '@playwright/test'
import { logIn, openFileAction, resetPerson, saveSettings } from './support'

test.beforeAll(async () => {
	await saveSettings({ mode: 'provision' })
})

test.beforeEach(async () => {
	await resetPerson('carol')
})

test.afterAll(async () => {
	await saveSettings({ mode: 'link' })
})

test('with accounts created by the administrator, a person activates AI once', async ({
	page,
}) => {
	await logIn(page, 'carol')
	const dialog = await openFileAction(page, 'Summarize with Synaplan')
	const summarize = dialog.getByRole('button', {
		name: 'Summarize',
		exact: true,
	})
	await expect(summarize).toHaveCount(0)

	await dialog.getByRole('button', { name: 'Activate AI' }).click()
	await summarize.click()

	await expect(dialog.locator('.result-md')).not.toBeEmpty()
	await expect(dialog.locator('.notecard')).toHaveCount(0)
})
