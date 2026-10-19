import { expect, test } from '@playwright/test'
import { allowOnSynaplan, logIn, openFileAction, resetPerson } from './support'

test.beforeEach(async () => {
	await resetPerson('ada')
})

test('a new person connects from the first file action', async ({ page }) => {
	await logIn(page, 'ada')
	const dialog = await openFileAction(page, 'Summarize with Synaplan')

	await expect(
		dialog.getByRole('button', { name: 'Summarize', exact: true }),
	).toHaveCount(0)
	await dialog.getByRole('button', { name: 'Connect my Synaplan account' }).click()

	await allowOnSynaplan(page)
	await expect(page.getByText(/Connected as demo@synaplan\.com/)).toBeVisible()
})
