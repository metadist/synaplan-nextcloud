import {
	expect,
	request,
	type APIRequestContext,
	type Locator,
	type Page,
} from '@playwright/test'

export const NC_URL = process.env.NC_URL ?? 'http://localhost:8081'
export const SYNAPLAN_URL = process.env.SYNAPLAN_URL ?? 'http://localhost:8001'
export const APP = '/index.php/apps/synaplan_integration'
export const FILE = 'vendor-review.txt'

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

/**
 * Ids of the Synaplan person's API keys that still work.
 *
 * @param synaplan API client signed in as the Synaplan person
 */
export async function keyIdsOf(synaplan: APIRequestContext): Promise<number[]> {
	const response = await synaplan.get('/api/v1/apikeys')
	expect(response.ok(), 'listing the Synaplan API keys').toBe(true)
	const body = (await response.json()) as { api_keys: Array<{ id: number }> }
	return body.api_keys.map((key) => key.id)
}
