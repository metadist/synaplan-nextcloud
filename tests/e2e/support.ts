import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, request } from '@playwright/test'
import { execFileSync } from 'node:child_process'

export const NC_URL = process.env.NC_URL ?? 'http://localhost:8081'
// A test-only name: SYNAPLAN_URL is what the dev hook and a deployment read,
// and the setup creates keys and registers a client wherever this points.
export const SYNAPLAN_URL = process.env.E2E_SYNAPLAN_URL ?? 'http://localhost:8001'
export const APP = '/index.php/apps/synaplan_integration'
export const FILE = 'vendor-review.txt'
export const SYNAPLAN_CONTAINER =
	process.env.SYNAPLAN_APP_CONTAINER ?? 'synaplan-app-test'

/**
 * Emails of the test people whose Synaplan account the journeys create.
 * The dev Nextcloud seeds carol@example.org on the same Synaplan.
 */
export const EMAIL: Record<string, string> = {
	carol: 'carol.nc-e2e@example.org',
}

/** Fixture accounts of the Synaplan test stack. */
export const SYNAPLAN_ADMIN = {
	email: 'admin@synaplan.com',
	password: 'admin123',
}
export const SYNAPLAN_PERSON = {
	email: 'demo@synaplan.com',
	password: 'demo123',
}

/**
 * Test people come from docker/dev-hooks; the admin password is `admin`.
 *
 * @param uid Nextcloud user id
 */
export function passwordOf(uid: string): string {
	return uid === 'admin' ? 'admin' : `${uid}-dev-pass`
}

/**
 * An API client signed in to Nextcloud with the person's password.
 *
 * @param uid Nextcloud user id
 */
export async function nextcloudAs(uid: string): Promise<APIRequestContext> {
	const credentials = Buffer.from(`${uid}:${passwordOf(uid)}`).toString('base64')
	return request.newContext({
		baseURL: NC_URL,
		extraHTTPHeaders: {
			Authorization: `Basic ${credentials}`,
			'OCS-APIRequest': 'true',
		},
	})
}

/**
 * An API client signed in to Synaplan.
 *
 * @param account Synaplan email and password
 * @param account.email Synaplan email
 * @param account.password Synaplan password
 */
export async function synaplanAs(account: {
	email: string
	password: string
}): Promise<APIRequestContext> {
	const context = await request.newContext({ baseURL: SYNAPLAN_URL })
	const response = await context.post('/api/v1/auth/login', {
		data: account,
	})
	expect(response.ok(), `Synaplan login as ${account.email}`).toBe(true)
	return context
}

/**
 * Save admin settings through the same endpoint as the admin page.
 *
 * @param settings the fields to change
 */
export async function saveSettings(
	settings: Record<string, unknown>,
): Promise<void> {
	const admin = await nextcloudAs('admin')
	const response = await admin.put(`${APP}/api/v1/settings`, {
		data: settings,
	})
	expect(response.ok(), 'saving the admin settings').toBe(true)
	await admin.dispose()
}

/**
 * Take a person's AI access away, as Deactivate in the admin settings does,
 * so a journey starts from a known state, also when Playwright retries it.
 *
 * @param uid Nextcloud user id
 */
export async function resetPerson(uid: string): Promise<void> {
	const admin = await nextcloudAs('admin')
	const response = await admin.post(
		`${APP}/api/v1/admin/ai-users/${uid}/deactivate`,
	)
	expect(response.ok(), `resetting ${uid}'s AI access`).toBe(true)
	await admin.dispose()
}

const NC_HOST = new URL(NC_URL).host
if (!/^[a-z0-9.-]+(:\d+)?$/i.test(NC_HOST)) {
	throw new Error(`unexpected Nextcloud host ${NC_HOST}`)
}

/** SQL condition on BPLATFORMINSTANCES p: a Nextcloud instance on this host. */
const OURS = `p.BCLIENT = 'nextcloud' AND p.BHOST = '${NC_HOST}'`

/**
 * SQL subquery for the ids of the Synaplan identities a person has at a
 * Nextcloud instance on this host, so a query on the shared test stack
 * leaves rows of the dev Nextcloud and ownCloud.online alone.
 *
 * @param uid Nextcloud user id
 */
export function ownIdentities(uid: string): string {
	if (!/^[a-z0-9._-]+$/i.test(uid)) {
		throw new Error(`unexpected uid ${uid}`)
	}
	return `SELECT i.BID FROM BEXTERNALIDENTITIES i
		JOIN BPLATFORMINSTANCES p ON p.BINSTANCEID = i.BINSTANCEID
		WHERE ${OURS} AND i.BEXTERNALID = '${uid}'`
}

/**
 * The Synaplan accounts this Nextcloud provisioned for a person: each has
 * an identity for the uid at a Nextcloud instance on this host (a kept
 * Synaplan also holds the instances of earlier runs), and none elsewhere.
 * The dev Nextcloud and ownCloud.online share the test stack and seed the
 * same people, so an email or "provisioned" alone names their accounts too.
 * Synaplan's admin API does not show identities, so this asks its database.
 *
 * @param uid Nextcloud user id
 */
export function ownedAccountIds(uid: string): number[] {
	return [...ownedAccounts(uid).keys()]
}

/**
 * The accounts of ownedAccountIds, each with its email in the database.
 *
 * @param uid Nextcloud user id
 */
function ownedAccounts(uid: string): Map<number, string> {
	const sql = `SELECT CONCAT('owned:', COALESCE(GROUP_CONCAT(DISTINCT CONCAT(u.BID, '=', u.BMAIL) SEPARATOR ';'), '')) AS owned
		FROM BEXTERNALIDENTITIES i JOIN BUSER u ON u.BID = i.BUSERID
		WHERE i.BID IN (${ownIdentities(uid)})
		AND i.BUSERID NOT IN (SELECT o.BUSERID FROM BEXTERNALIDENTITIES o
			LEFT JOIN BPLATFORMINSTANCES p ON p.BINSTANCEID = o.BINSTANCEID
			WHERE p.BID IS NULL OR NOT (${OURS}))`
	const out = execFileSync('docker', [
		'exec',
		SYNAPLAN_CONTAINER,
		'php',
		'bin/console',
		'dbal:run-sql',
		sql.replace(/\s+/g, ' '),
	]).toString()
	const match = /owned:(\S*)/.exec(out)
	if (match === null) {
		throw new Error(`could not read the accounts of ${uid}: ${out}`)
	}
	const accounts = new Map<number, string>()
	for (const pair of match[1].split(';').filter((entry) => entry !== '')) {
		const at = pair.indexOf('=')
		accounts.set(Number(pair.slice(0, at)), pair.slice(at + 1))
	}
	return accounts
}

/**
 * The email of a Synaplan account.
 *
 * @param id Synaplan user id
 */
export async function accountEmail(id: number): Promise<string> {
	const synaplan = await synaplanAs(SYNAPLAN_ADMIN)
	const found = await synaplan.get(`/api/v1/admin/users/${id}/usage`)
	expect(found.ok(), `reading Synaplan account ${id}`).toBe(true)
	const { user } = (await found.json()) as { user?: { email?: string } }
	await synaplan.dispose()
	return user?.email ?? ''
}

/**
 * Delete the Synaplan accounts this Nextcloud provisioned for a person, so
 * a provisioning journey starts without one, even after a release that
 * revoked the account on Deactivate. Accounts of other clouds sharing the
 * test stack are never touched.
 *
 * @param uid Nextcloud user id
 */
export async function deleteSynaplanAccount(uid: string): Promise<void> {
	const accounts = ownedAccounts(uid)
	const synaplan = await synaplanAs(SYNAPLAN_ADMIN)
	for (const [id, email] of accounts) {
		// The database is read in SYNAPLAN_APP_CONTAINER, the deletion goes to
		// E2E_SYNAPLAN_URL: an account that differs between them means two
		// stacks, where the id may name someone else's account.
		const found = await synaplan.get(`/api/v1/admin/users/${id}/usage`)
		const seen = found.ok()
			? (((await found.json()) as { user?: { email?: string } }).user?.email
				?? '')
			: ''
		if (seen !== email) {
			await synaplan.dispose()
			throw new Error(
				`Synaplan account ${id} is ${email} in ${SYNAPLAN_CONTAINER} but ${seen || 'missing'} at ${SYNAPLAN_URL}: they are different stacks, so nothing was deleted. Point SYNAPLAN_APP_CONTAINER and E2E_SYNAPLAN_URL at one.`,
			)
		}
	}
	for (const id of accounts.keys()) {
		const deleted = await synaplan.delete(`/api/v1/admin/users/${id}`)
		expect(deleted.ok(), `deleting ${uid}'s account ${id} from Synaplan`).toBe(
			true,
		)
	}
	await synaplan.dispose()
}

/**
 * Sign in on the Nextcloud login page.
 *
 * @param page the browser page
 * @param uid Nextcloud user id
 */
export async function logIn(page: Page, uid: string): Promise<void> {
	await page.goto('/index.php/login')
	await page.fill('#user', uid)
	await page.fill('#password', passwordOf(uid))
	await page.click('button[type="submit"]')
	await page.waitForURL(/apps\/(files|dashboard)/)
}

/**
 * Right-click the file in Files and open one of the app's actions.
 *
 * @param page the browser page
 * @param action the menu entry, e.g. "Summarize with Synaplan"
 * @param file the file name in the person's root folder
 */
export async function openFileAction(
	page: Page,
	action: string,
	file = FILE,
): Promise<Locator> {
	await page.goto('/index.php/apps/files/')
	const row = page.locator(`[data-cy-files-list-row-name="${file}"]`)
	await row.click({ button: 'right' })
	await page.getByRole('menuitem', { name: action }).click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible()
	return dialog
}

/**
 * Sign in on Synaplan's connect page and allow the link.
 *
 * @param page the browser page, on its way to Synaplan
 */
export async function allowOnSynaplan(page: Page): Promise<void> {
	await page.waitForURL(`${SYNAPLAN_URL}/**`)
	const email = page.getByTestId('input-email')
	const connect = page.getByTestId('btn-connect')
	await expect(email.or(connect)).toBeVisible()
	if (await email.isVisible()) {
		await email.fill(SYNAPLAN_PERSON.email)
		await page.getByTestId('input-password').fill(SYNAPLAN_PERSON.password)
		await page.getByTestId('btn-login').click()
	}
	await expect(connect).toBeVisible()
	await connect.click()
	await page.waitForURL(`${NC_URL}/**`)
}

const CONNECTED = /Connected as demo@synaplan\.com/

/**
 * Connect from the personal settings, as a person would.
 *
 * @param page the browser page, signed in to Nextcloud
 */
export async function connectFromSettings(page: Page): Promise<void> {
	await page.goto('/index.php/settings/user/synaplan_integration')
	await page.getByRole('link', { name: 'Connect Synaplan' }).click()
	await allowOnSynaplan(page)
	await expect(page.getByText(CONNECTED)).toBeVisible()
}

/**
 * Connect unless the personal settings already say "Connected as …".
 *
 * @param page the browser page, signed in to Nextcloud
 */
export async function ensureConnected(page: Page): Promise<void> {
	await page.goto('/index.php/settings/user/synaplan_integration')
	const connect = page.getByRole('link', { name: 'Connect Synaplan' })
	const connected = page.getByText(CONNECTED)
	await expect(connect.or(connected)).toBeVisible()
	if (await connect.isVisible()) {
		await connect.click()
		await allowOnSynaplan(page)
		await expect(connected).toBeVisible()
	}
}

/**
 * Make sure the file is not searchable by AI, through the app's own API, so
 * the knowledge journey starts from the same state on a retry.
 *
 * @param uid Nextcloud user id
 * @param file the file name in the person's root folder
 */
export async function makeUnsearchable(uid: string, file = FILE): Promise<void> {
	const person = await nextcloudAs(uid)
	const found = await person.fetch(`/remote.php/dav/files/${uid}/${file}`, {
		method: 'PROPFIND',
		headers: { Depth: '0', 'Content-Type': 'application/xml' },
		data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	const fileId = /<oc:fileid>(\d+)<\/oc:fileid>/.exec(await found.text())?.[1]
	expect(fileId, `the file id of ${file}`).toBeTruthy()
	const status = await person.get(`${APP}/api/v1/knowledge/status/${fileId}`)
	const { in_knowledge: searchable } = (await status.json()) as {
		in_knowledge?: boolean
	}
	if (searchable) {
		const removed = await person.delete(`${APP}/api/v1/knowledge/${fileId}`)
		expect(removed.ok(), `removing ${file} from AI search`).toBe(true)
	}
	await person.dispose()
}

interface Link {
	id: number
	client: string
	host: string
	external_id: string
	key_id: number | null
}

/**
 * The Synaplan person's links that come from this Nextcloud for one person.
 *
 * @param synaplan API client signed in as the Synaplan person
 * @param uid Nextcloud user id
 */
export async function linksOf(
	synaplan: APIRequestContext,
	uid: string,
): Promise<Link[]> {
	const response = await synaplan.get('/api/v1/me/platform-links')
	expect(response.ok(), 'listing the Synaplan links').toBe(true)
	const body = (await response.json()) as { links: Link[] }
	const host = new URL(NC_URL).host
	return body.links.filter(
		(link) =>
			link.client === 'nextcloud'
			&& link.host === host
			&& link.external_id === uid,
	)
}
