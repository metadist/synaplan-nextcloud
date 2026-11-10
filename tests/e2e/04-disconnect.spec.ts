import { expect, test } from '@playwright/test'
import {
	SYNAPLAN_PERSON,
	allowOnSynaplan,
	connectFromSettings,
	ensureConnected,
	linksOf,
	logIn,
	openFileAction,
	resetPerson,
	synaplanAs,
} from './support'

// Synaplan tells this Nextcloud right away (identity_revoked), so the next
// action asks to connect again instead of failing, and connecting works.
test('after a disconnect in Synaplan, the next action asks to connect again', async ({
	page,
}) => {
	await logIn(page, 'ada')
	await ensureConnected(page)

	const synaplan = await synaplanAs(SYNAPLAN_PERSON)
	const links = await linksOf(synaplan, 'ada')
	expect(links.length, 'ada is linked').toBeGreaterThan(0)
	for (const link of links) {
		const removed = await synaplan.delete(`/api/v1/me/platform-links/${link.id}`)
		expect(removed.ok(), `disconnecting link ${link.id}`).toBe(true)
	}

	const dialog = await openFileAction(page, 'Summarize with Synaplan')
	await expect(
		dialog.getByRole('button', { name: 'Summarize', exact: true }),
	).toHaveCount(0)
	await dialog.getByRole('button', { name: 'Connect my Synaplan account' }).click()
	await allowOnSynaplan(page)
	await expect(page.getByText(/Connected as/)).toBeVisible()
	expect((await linksOf(synaplan, 'ada')).length, 'ada is linked again').toBe(1)
	await synaplan.dispose()
})

test('a disconnect in Nextcloud ends the link in Synaplan', async ({ page }) => {
	await resetPerson('ada')
	await logIn(page, 'ada')
	await connectFromSettings(page)
	const synaplan = await synaplanAs(SYNAPLAN_PERSON)
	expect((await linksOf(synaplan, 'ada')).length, 'ada is linked').toBe(1)

	await page.getByRole('button', { name: 'Disconnect' }).click()

	await expect(page.getByText('Not connected')).toBeVisible()
	await expect.poll(async () => (await linksOf(synaplan, 'ada')).length).toBe(0)
	await synaplan.dispose()
})
