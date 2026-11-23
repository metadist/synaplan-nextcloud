import type { APIRequestContext } from '@playwright/test'

import { expect, request } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import {
	APP,
	EMAIL,
	FILE,
	NC_URL,
	nextcloudAs,
	resetPerson,
	saveSettings,
	SYNAPLAN_ADMIN,
	SYNAPLAN_URL,
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

/** The initial access token the Synaplan test stack accepts (synaplan-test-stack.yml). */
const REGISTRATION_TOKEN = 'nextcloud-e2e-initial-access-token-0001'

/**
 * This Nextcloud's registration status at Synaplan, '' when not registered.
 *
 * @param admin request context signed in as the Nextcloud administrator
 */
async function registrationStatus(admin: APIRequestContext): Promise<string> {
	const response = await admin.get(`${APP}/api/v1/settings/instance-status`)
	expect(response.ok(), 'reading the registration status').toBe(true)
	const { instance } = (await response.json()) as {
		instance: { status?: string } | null
	}
	return instance?.status ?? ''
}

/**
 * Registers this Nextcloud with Synaplan.
 *
 * @param admin request context signed in as the Nextcloud administrator
 * @param token initial access token, '' for none
 * @return the error Nextcloud reports, '' on success
 */
async function register(admin: APIRequestContext, token: string): Promise<string> {
	const response = await admin.post(`${APP}/api/v1/settings/register-instance`, {
		data: { registration_token: token },
	})
	const { error } = (await response.json()) as { error?: string }
	return error ?? ''
}

/**
 * Connects the app to the Synaplan test stack the way an administrator
 * does: a Synaplan API key in the admin settings, "Connect your Synaplan
 * account" as the mode, this Nextcloud registered. Each test person starts
 * without AI access and gets the file the journeys work on, so the suite
 * also runs again on the same stacks.
 */
const SYNAPLAN_REDIS_CONTAINER =
	process.env.SYNAPLAN_REDIS_CONTAINER ?? 'synaplan-redis-test'

/**
 * Synaplan allows a registered client 30 updates an hour and has no setting
 * to lift that. Every saved admin setting is one update, so a test stack
 * that stays up between runs locks this Nextcloud out within a few runs.
 * Clear those counters on the test stack's Redis before each run.
 */
function resetClientUpdateLimit(): void {
	const redis = (...args: string[]) =>
		execFileSync('docker', [
			'exec',
			SYNAPLAN_REDIS_CONTAINER,
			'redis-cli',
			...args,
		])
			.toString()
			.split('\n')
			.filter((line) => line.trim() !== '')
	let keys: string[]
	try {
		keys = redis('--scan', '--pattern', '*oauth:client-update:*')
	} catch (error) {
		throw new Error(
			`Could not reach the Synaplan test Redis in ${SYNAPLAN_REDIS_CONTAINER}; set SYNAPLAN_REDIS_CONTAINER.`,
			{ cause: error },
		)
	}
	if (keys.length > 0) {
		redis('del', ...keys)
	}
}

export default async function globalSetup(): Promise<void> {
	await waitUntilReady(`${SYNAPLAN_URL}/api/health`)
	await waitUntilReady(`${NC_URL}/status.php`)
	resetClientUpdateLimit()

	const synaplan = await synaplanAs(SYNAPLAN_ADMIN)
	const created = await synaplan.post('/api/v1/apikeys', {
		data: { name: `Nextcloud e2e ${new Date().toISOString()}` },
	})
	expect(created.ok(), 'creating the Synaplan API key').toBe(true)
	const { api_key: apiKey } = (await created.json()) as {
		api_key: { key: string }
	}
	await synaplan.dispose()

	// Synaplan's container reaches this Nextcloud on the Docker host, under
	// a name Synaplan's test environment accepts for plain-http delivery.
	const inbound = new URL(`${APP}/api/v1/inbound`, NC_URL)
	inbound.hostname = 'host.docker.internal'
	await saveSettings({
		api_key_local: apiKey.key,
		active_env: 'local',
		mode: 'link',
		link_auto_provision: false,
		delivery_url: inbound.toString(),
	})

	const admin = await nextcloudAs('admin')
	if ((await registrationStatus(admin)) !== 'active') {
		await admin.post(`${APP}/api/v1/settings/forget-instance`)
		let error = await register(admin, REGISTRATION_TOKEN)
		// The token is single-use. A Synaplan test stack kept from an earlier
		// run (E2E_KEEP_SYNAPLAN, or the other app's) registers openly.
		if (/already used/.test(error)) {
			error = await register(admin, '')
		}
		expect(error, 'registering Nextcloud').toBe('')
	}
	expect(await registrationStatus(admin), 'the instance is active').toBe('active')

	for (const [uid, email] of Object.entries(EMAIL)) {
		const changed = await admin.put(`/ocs/v2.php/cloud/users/${uid}`, {
			form: { key: 'email', value: email },
		})
		expect(changed.ok(), `giving ${uid} the email ${email}`).toBe(true)
	}
	await admin.dispose()

	for (const uid of PEOPLE) {
		await resetPerson(uid)
		const person = await nextcloudAs(uid)
		const uploaded = await person.put(`/remote.php/dav/files/${uid}/${FILE}`, {
			data: REVIEW_TEXT,
			headers: { 'Content-Type': 'text/plain' },
		})
		expect(uploaded.ok(), `uploading ${FILE} for ${uid}`).toBe(true)
		await person.dispose()
	}
}
