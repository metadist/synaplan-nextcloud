import { expect, test } from '@playwright/test'
import { logIn, nextcloudAs, openFileAction } from './support'

// ada connected in 02-connect.spec.ts.
test.beforeEach(async ({ page }) => {
	await logIn(page, 'ada')
})

test('Summarize writes a summary', async ({ page }) => {
	const dialog = await openFileAction(page, 'Summarize with Synaplan')
	await dialog.getByRole('button', { name: 'Summarize', exact: true }).click()

	await expect(dialog.locator('.result-md')).not.toBeEmpty()
	await expect(dialog.locator('.notecard')).toHaveCount(0)
})

test('Translate writes a translation', async ({ page }) => {
	const dialog = await openFileAction(page, 'Translate with Synaplan')
	await dialog.getByRole('button', { name: 'Translate', exact: true }).click()

	await expect(dialog.locator('.result-md')).not.toBeEmpty()
	await expect(dialog.locator('.notecard')).toHaveCount(0)
})

test('Chat about this file answers a question', async ({ page }) => {
	const dialog = await openFileAction(page, 'Chat about this file')
	await dialog
		.getByPlaceholder('Ask a question about this file...')
		.fill('What does the review recommend?')
	await dialog.getByRole('button', { name: 'Send' }).click()

	const answer = dialog.locator('.message.assistant .message-content').last()
	await expect(answer).not.toBeEmpty()
	await expect(answer).not.toHaveClass(/loading-dots/)
	await expect(dialog.locator('.notecard')).toHaveCount(0)
})

test('Make searchable by AI, then remove it again', async ({ page }) => {
	let dialog = await openFileAction(page, 'Make searchable by AI')
	await dialog.getByRole('combobox').click()
	await page.keyboard.type('Vendors')
	await page.keyboard.press('Enter')
	await dialog.getByRole('button', { name: 'Make searchable' }).click()

	await expect(dialog.getByText('This file is now searchable by AI')).toBeVisible()
	await dialog.getByRole('button', { name: 'Done' }).click()

	dialog = await openFileAction(page, 'Make searchable by AI')
	await expect(
		dialog.getByText('This file is already searchable by AI.', {
			exact: false,
		}),
	).toBeVisible()
	await dialog.getByRole('button', { name: 'Remove from AI search' }).click()
	await expect(
		dialog.getByText('This file is no longer searchable by AI'),
	).toBeVisible()
})

test('an image from the chat is saved to Synaplan/Images', async ({ page }) => {
	await page.goto('/index.php/apps/synaplan_integration/research')
	await page.getByPlaceholder(/Ask a question/).fill('/pic a red bicycle')
	await page.getByRole('button', { name: 'Send' }).click()

	await expect(page.getByAltText('Generated image')).toBeVisible()
	await page.getByRole('button', { name: 'Save to Nextcloud' }).click()
	const saved = page.getByText(/Saved to .*Synaplan\/Images\//)
	await expect(saved).toBeVisible()

	const path = (await saved.innerText()).replace(/^Saved to\s*/, '').trim()
	const ada = await nextcloudAs('ada')
	const file = await ada.fetch(
		`/remote.php/dav/files/ada/${path.replace(/^\//, '')}`,
		{ method: 'PROPFIND', headers: { Depth: '0' } },
	)
	expect(file.status(), `${path} exists in ada's files`).toBe(207)
	await ada.dispose()
})
