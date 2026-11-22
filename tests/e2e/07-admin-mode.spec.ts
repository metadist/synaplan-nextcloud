import { expect, test } from '@playwright/test'
import { logIn, saveSettings } from './support'

test.beforeEach(async () => {
	await saveSettings({ mode: 'link' })
})

test.afterAll(async () => {
	await saveSettings({ mode: 'link', watch_shared_folders: true })
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

test('an administrator turns off watching shared folders and the choice is kept', async ({
	page,
}) => {
	await saveSettings({ watch_shared_folders: true })
	await logIn(page, 'admin')
	await page.goto('/index.php/settings/admin/synaplan_integration')
	const allow = page.getByRole('switch', { name: 'Allow watching shared folders' })
	await expect(allow).toBeChecked()

	await page.getByText('Allow watching shared folders').click()
	await expect(allow).not.toBeChecked()
	await page
		.locator('.settings-section', { has: allow })
		.getByRole('button', { name: 'Save', exact: true })
		.click()
	await expect(page.getByText('Settings saved successfully.')).toBeVisible()
	await page.reload()
	await expect(allow).not.toBeChecked()
})

test('an administrator turns off watching folders, which also locks the shared-folders switch', async ({
	page,
}) => {
	await saveSettings({ watch_folders: true })
	await logIn(page, 'admin')
	await page.goto('/index.php/settings/admin/synaplan_integration')
	const allow = page.getByRole('switch', {
		name: 'Allow watching folders',
		exact: true,
	})
	const shared = page.getByRole('switch', {
		name: 'Allow watching shared folders',
	})
	await expect(allow).toBeChecked()
	await expect(shared).toBeEnabled()

	try {
		await page.getByText('Allow watching folders', { exact: true }).click()
		await expect(allow).not.toBeChecked()
		await expect(shared).toBeDisabled()
		await page
			.locator('.settings-section', { has: allow })
			.getByRole('button', { name: 'Save', exact: true })
			.click()
		await expect(page.getByText('Settings saved successfully.')).toBeVisible()
		await page.reload()
		await expect(allow).not.toBeChecked()
		await expect(shared).toBeDisabled()
	} finally {
		await saveSettings({ watch_folders: true })
	}
})
