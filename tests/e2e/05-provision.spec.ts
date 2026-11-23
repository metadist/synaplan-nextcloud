import type { Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	accountEmail,
	deleteSynaplanAccount,
	EMAIL,
	logIn,
	openFileAction,
	ownedAccountIds,
	resetPerson,
	saveSettings,
} from './support'

test.beforeAll(async () => {
	await saveSettings({ mode: 'provision' })
	await resetPerson('carol')
	await deleteSynaplanAccount('carol')
})

test.beforeEach(async () => {
	await resetPerson('carol')
})

test.afterAll(async () => {
	await saveSettings({ mode: 'link' })
})

/**
 * Activate AI from the summary dialog and summarize the file.
 *
 * @param page the browser page
 */
async function activateAndSummarize(page: Page): Promise<Locator> {
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
	return dialog
}

test('with accounts created by the administrator, a person activates AI once', async ({
	page,
}) => {
	await logIn(page, 'carol')
	await activateAndSummarize(page)
	const [account] = ownedAccountIds('carol')
	expect(await accountEmail(account)).toBe(EMAIL.carol)
})

test('after the administrator deactivates AI, the person turns it back on with the same account', async ({
	page,
}) => {
	await logIn(page, 'carol')
	await activateAndSummarize(page)
	const account = ownedAccountIds('carol')
	expect(account).toHaveLength(1)

	await resetPerson('carol')
	await page.reload()
	await activateAndSummarize(page)

	expect(ownedAccountIds('carol')).toEqual(account)
})
