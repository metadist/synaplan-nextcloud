import { expect, request } from '@playwright/test'
import {
	APP,
	FILE,
	NC_URL,
	SYNAPLAN_ADMIN,
	SYNAPLAN_URL,
	nextcloudAs,
	saveSettings,
	synaplanAs,
} from './support'

const READY_TIMEOUT_MS = 180_000

/** The test people the journeys sign in as. */
const PEOPLE = ['ada', 'carol']

const REVIEW_TEXT = `Vendor review: Northwind Office Supplies

Northwind delivered 96 of 100 orders on time this quarter. Two deliveries
arrived damaged and were replaced within a week. Prices rose by 4 percent in
March. The account manager answers within one working day.

Recommendation: keep Northwind for another year and ask for a fixed price
until December.
`

/**
 * Wait until the URL answers 200.
 *
 * @param url health or status endpoint
 */
async function waitUntilReady(url: string): Promise<void> {
	const context = await request.newContext()
	await expect
		.poll(
			async () => {
				try {
					return (await context.get(url)).status()
				} catch {
					return 0
				}
			},
			{ timeout: READY_TIMEOUT_MS, intervals: [2_000] },
		)
		.toBe(200)
	await context.dispose()
}

/**
 * Connects the app to the Synaplan test stack the way an administrator
 * does: a Synaplan API key in the admin settings, "Connect your Synaplan
 * account" as the mode, this Nextcloud registered. Each test person starts
 * without AI access and gets the file the journeys work on, so the suite
 * also runs again on the same stacks.
 */
export default async function globalSetup(): Promise<void> {
	await waitUntilReady(`${SYNAPLAN_URL}/api/health`)
	await waitUntilReady(`${NC_URL}/status.php`)

	const synaplan = await synaplanAs(SYNAPLAN_ADMIN)
	const created = await synaplan.post('/api/v1/apikeys', {
		data: { name: `Nextcloud e2e ${new Date().toISOString()}` },
	})
	expect(created.ok(), 'creating the Synaplan API key').toBe(true)
	const { api_key: apiKey } = (await created.json()) as {
		api_key: { key: string }
	}
	await synaplan.dispose()

	await saveSettings({
		api_key_local: apiKey.key,
		active_env: 'local',
		mode: 'link',
		link_auto_provision: false,
	})

	const admin = await nextcloudAs('admin')
	const registered = await admin.post(`${APP}/api/v1/settings/register-instance`)
	const registration = (await registered.json()) as {
		error?: string
		status?: string
	}
	expect(registration.error ?? '', 'registering Nextcloud').toBe('')
	expect(registration.status, 'the instance is active').toBe('active')

	for (const uid of PEOPLE) {
		const reset = await admin.post(
			`${APP}/api/v1/admin/ai-users/${uid}/deactivate`,
		)
		expect(reset.ok(), `resetting ${uid}'s AI access`).toBe(true)
	}
	await admin.dispose()

	for (const uid of PEOPLE) {
		const person = await nextcloudAs(uid)
		const uploaded = await person.put(`/remote.php/dav/files/${uid}/${FILE}`, {
			data: REVIEW_TEXT,
			headers: { 'Content-Type': 'text/plain' },
		})
		expect(uploaded.ok(), `uploading ${FILE} for ${uid}`).toBe(true)
		await person.dispose()
	}
}
