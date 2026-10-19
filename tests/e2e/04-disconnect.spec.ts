import { expect, test } from '@playwright/test'
import {
	SYNAPLAN_PERSON,
	connectFromSettings,
	keyIdsOf,
	linksOf,
	logIn,
	openFileAction,
	synaplanAs,
} from './support'

test('after a disconnect in Synaplan, the next action says how to reconnect', async ({
	page,
}) => {
	const synaplan = await synaplanAs(SYNAPLAN_PERSON)
	const links = await linksOf(synaplan, 'ada')
	expect(links.length, 'ada is linked from 02-connect').toBeGreaterThan(0)
	for (const link of links) {
		const removed = await synaplan.delete(`/api/v1/me/platform-links/${link.id}`)
		expect(removed.ok(), `disconnecting link ${link.id}`).toBe(true)
	}
	await synaplan.dispose()

	await logIn(page, 'ada')
	const dialog = await openFileAction(page, 'Summarize with Synaplan')
	await dialog.getByRole('button', { name: 'Summarize', exact: true }).click()

	const note = dialog.locator('.notecard')
	await expect(note).toContainText('Connect your Synaplan account first.')
	await note.getByRole('link', { name: 'Open Synaplan settings' }).click()
	await expect(page.getByText('Not connected')).toBeVisible()
	await expect(page.getByRole('link', { name: 'Connect Synaplan' })).toBeVisible()
})

// Synaplan keeps listing the link after its key is gone; only the key is
// checked here until the core removes the link with its key.
test('a disconnect in Nextcloud revokes the key in Synaplan', async ({ page }) => {
	await logIn(page, 'ada')
	await connectFromSettings(page)
	const synaplan = await synaplanAs(SYNAPLAN_PERSON)
	const [link] = await linksOf(synaplan, 'ada')
	const keyId = link?.key_id
	expect(keyId, 'the link holds a key').toBeTruthy()
	expect(await keyIdsOf(synaplan)).toContain(keyId)

	await page.getByRole('button', { name: 'Disconnect' }).click()

	await expect(page.getByText('Not connected')).toBeVisible()
	await expect.poll(async () => keyIdsOf(synaplan)).not.toContain(keyId)
	await synaplan.dispose()
})
