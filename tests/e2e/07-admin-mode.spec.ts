import { expect, test } from '@playwright/test'
import { logIn, saveSettings } from './support'

test.beforeEach(async () => {
	await saveSettings({ mode: 'link' })
})

test.afterAll(async () => {
	await saveSettings({ mode: 'link' })
})

test('an administrator picks how people use Synaplan and the choice is kept', async ({
	page,
}) => {
	await logIn(page, 'admin')
	await page.goto('/index.php/settings/admin/synaplan_integration')
	const provision = page.locator('input[name="sp-mode"][value="provision"]')
	await expect(page.locator('input[name="sp-mode"][value="link"]')).toBeChecked()

	await page.locator('.sp-mode-choice', { has: provision }).click()
	await expect(provision).toBeChecked()

	await page
		.locator('.settings-section', { has: provision })
		.getByRole('button', { name: 'Save', exact: true })
		.click()
	await expect(page.getByText('Settings saved successfully.')).toBeVisible()
	await page.reload()
	await expect(provision).toBeChecked()
})
