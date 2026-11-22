import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import {
	APP,
	ensureConnected,
	logIn,
	nextcloudAs,
	openFileAction,
	saveSettings,
} from './support'

/**
 * Watch with Synaplan feeds the watch from this Nextcloud: a file dropped
 * into a watched folder waits out the quiet period, the every-minute job
 * sends it to POST /api/v1/cloud/watches/{id}/files with the person's own
 * credential, and Synaplan finishes it once it has been quiet there too.
 *
 * Synaplan's sweep runs from its scheduler, which the test stack does not
 * start, so the journey runs `app:cloud-watches:sweep` itself. The journey
 * reads the watch from the test stack's database because Synaplan lists
 * watches only to the cloud they belong to.
 */

const UID = 'ada'
const SHARER = 'bob'
const READ = 1
const EDIT = 31
const POLICY_OFF = 'Stopped — your administrator turned off watching shared folders'
const SYNAPLAN_CONTAINER = process.env.SYNAPLAN_APP_CONTAINER ?? 'synaplan-app-test'
const SYNAPLAN_DB_CONTAINER = process.env.SYNAPLAN_DB_CONTAINER ?? 'synaplan-db-test'
const NC_PROJECT = process.env.E2E_NC_PROJECT ?? 'synaplan-nextcloud-e2e'
const SYNAPLAN_QUIET_SECONDS = 60

test.describe.configure({ mode: 'serial' })

test.beforeEach(async ({ page }) => {
	await logIn(page, UID)
	await ensureConnected(page)
})

test('a file dropped into a watched folder reaches its watch and is finished there', async ({
	page,
}) => {
	test.setTimeout(240_000)
	const folder = `Watched-${Date.now()}`
	const person = await nextcloudAs(UID)
	const dav = `/remote.php/dav/files/${UID}/${folder}`
	let watchId = ''
	expect((await person.fetch(dav, { method: 'MKCOL' })).ok()).toBe(true)
	const folderId = await fileIdOf(person, dav)
	expect(
		(await put(person, `${dav}/already-here.txt`, 'here before the watch')).ok(),
	).toBe(true)
	const alreadyId = await fileIdOf(person, `${dav}/already-here.txt`)
	occ('config:app:set', 'synaplan_integration', 'watch_quiet_seconds', '--value=1')

	try {
		const dialog = await openFileAction(page, 'Watch with Synaplan…', folder)
		const existing = dialog.getByText(
			'Also the files already in this folder (1)',
		)
		await expect(existing).toBeVisible()
		await existing.click()
		await dialog.getByRole('button', { name: 'Start watching' }).click()
		await expect(
			dialog.getByText(`Synaplan is watching “${folder}”.`),
		).toBeVisible()

		watchId = synaplanSql(
			`SELECT BID FROM BCLOUDWATCHES WHERE BTARGETID='${folderId}' AND BSTATE='active' ORDER BY BID DESC LIMIT 1`,
		)
		expect(watchId, 'the watch Synaplan started').toMatch(/^\d+$/)

		expect(
			(
				await put(person, `${dav}/dropped.txt`, 'a new file for the watch')
			).ok(),
		).toBe(true)
		const droppedId = await fileIdOf(person, `${dav}/dropped.txt`)
		await new Promise((resolve) => setTimeout(resolve, 1500))
		runJob('WatchFeedJob')

		const itemOf = (fileId: string) =>
			synaplanSql(
				`SELECT BSTATE FROM BCLOUDWATCHITEMS WHERE BWATCHID=${watchId} AND BSOURCEID='${fileId}'`,
			)
		expect(itemOf(droppedId), 'the dropped file reached the watch').toBe(
			'waiting',
		)
		expect(itemOf(alreadyId), 'the file already there stays out').toBe('')

		const updated = Number(
			synaplanSql(
				`SELECT BUPDATED FROM BCLOUDWATCHITEMS WHERE BWATCHID=${watchId} AND BSOURCEID='${droppedId}'`,
			),
		)
		const wait =
			updated + SYNAPLAN_QUIET_SECONDS + 1 - Math.floor(Date.now() / 1000)
		await new Promise((resolve) => setTimeout(resolve, Math.max(0, wait) * 1000))
		execFileSync('docker', [
			'exec',
			SYNAPLAN_CONTAINER,
			'php',
			'bin/console',
			'app:cloud-watches:sweep',
		])
		expect(itemOf(droppedId), 'Synaplan finished the file').toBe('done')
	} finally {
		occ('config:app:delete', 'synaplan_integration', 'watch_quiet_seconds')
		if (watchId !== '') {
			// Synaplan keeps 20 watches per person that are not stopped.
			synaplanSql(
				`UPDATE BCLOUDWATCHES SET BSTATE='stopped' WHERE BID=${watchId}`,
			)
		}
		await person.delete(dav)
		await person.dispose()
	}
})

test('a folder the person may only read offers no watch and cannot be watched', async ({
	page,
}) => {
	const folder = `Read-only-${Date.now()}`
	const sharer = await nextcloudAs(SHARER)
	const person = await nextcloudAs(UID)
	const dav = `/remote.php/dav/files/${SHARER}/${folder}`
	expect((await sharer.fetch(dav, { method: 'MKCOL' })).ok()).toBe(true)
	try {
		const folderId = await fileIdOf(sharer, dav)
		await shareWith(sharer, folder, UID, READ)

		await page.goto('/index.php/apps/files/')
		await page
			.locator(`[data-cy-files-list-row-name="${folder}"]`)
			.click({ button: 'right' })
		await expect(page.getByRole('menuitem').first()).toBeVisible()
		await expect(
			page.getByRole('menuitem', { name: 'Watch with Synaplan…' }),
		).toHaveCount(0)

		const refused = await person.post(`${APP}/api/v1/watches`, {
			data: {
				targetKind: 'folder',
				targetId: folderId,
				preset: 'keep_searchable',
			},
		})
		expect(refused.status()).toBe(403)
		expect((await refused.json()).error).toBe('watch_needs_edit')
	} finally {
		await sharer.delete(dav)
		await sharer.dispose()
		await person.dispose()
	}
})

test('with watching shared folders off, a shared folder offers no watch and its watch reads stopped', async ({
	page,
}) => {
	const folder = `Team-${Date.now()}`
	const person = await nextcloudAs(UID)
	const dav = `/remote.php/dav/files/${UID}/${folder}`
	let watchId = ''
	expect((await person.fetch(dav, { method: 'MKCOL' })).ok()).toBe(true)
	try {
		const folderId = await fileIdOf(person, dav)
		const started = await person.post(`${APP}/api/v1/watches`, {
			data: {
				targetKind: 'folder',
				targetId: folderId,
				preset: 'keep_searchable',
			},
		})
		expect(
			started.ok(),
			'starting the watch while shared folders are allowed',
		).toBe(true)
		watchId = String((await started.json()).watch.id)
		await shareWith(person, folder, SHARER, EDIT)
		await saveSettings({ watch_shared_folders: false })

		await page.goto('/index.php/apps/files/')
		const row = page.locator(`[data-cy-files-list-row-name="${folder}"]`)
		await expect(row.getByTestId('synaplan-row-bird')).toHaveAttribute(
			'title',
			'Watching stopped — open to see why',
		)
		await row.click({ button: 'right' })
		await expect(page.getByRole('menuitem').first()).toBeVisible()
		await expect(
			page.getByRole('menuitem', { name: 'Watch with Synaplan…' }),
		).toHaveCount(0)
		await page.keyboard.press('Escape')

		await row.getByTestId('synaplan-row-bird').click()
		const sidebar = page.getByTestId('synaplan-sidebar-tab')
		await expect(sidebar.getByText(POLICY_OFF)).toBeVisible()
		await expect(
			sidebar.getByTestId('synaplan-sidebar-watch-stop'),
		).toBeVisible()
		await expect(
			sidebar.getByTestId('synaplan-sidebar-watch-pause'),
		).toHaveCount(0)
		await expect(
			sidebar.getByTestId('synaplan-sidebar-watch-resume'),
		).toHaveCount(0)

		const refused = await person.post(`${APP}/api/v1/watches`, {
			data: {
				targetKind: 'folder',
				targetId: folderId,
				preset: 'keep_searchable',
			},
		})
		expect(refused.status()).toBe(403)
		expect((await refused.json()).error).toBe('watch_shared_off')
	} finally {
		await saveSettings({ watch_shared_folders: true })
		if (watchId !== '') {
			synaplanSql(
				`UPDATE BCLOUDWATCHES SET BSTATE='stopped' WHERE BID=${watchId}`,
			)
		}
		await person.delete(dav)
		await person.dispose()
	}
})

test('the owner pauses and resumes a watch in the Watched folders list and stops it in the sidebar', async ({
	page,
}) => {
	const folder = `Owned-${Date.now()}`
	const person = await nextcloudAs(UID)
	const dav = `/remote.php/dav/files/${UID}/${folder}`
	let watchId = ''
	expect((await person.fetch(dav, { method: 'MKCOL' })).ok()).toBe(true)
	try {
		const folderId = await fileIdOf(person, dav)
		const started = await person.post(`${APP}/api/v1/watches`, {
			data: {
				targetKind: 'folder',
				targetId: folderId,
				preset: 'keep_searchable',
			},
		})
		expect(started.ok(), 'starting the watch').toBe(true)
		watchId = String((await started.json()).watch.id)
		const stateOf = () =>
			synaplanSql(`SELECT BSTATE FROM BCLOUDWATCHES WHERE BID=${watchId}`)

		await page.goto(`${APP}/research`)
		const row = page
			.getByTestId('synaplan-watched-row')
			.filter({ hasText: folder })
		await expect(row).toBeVisible()
		await expect(row.getByTestId('synaplan-watched-open')).toHaveAttribute(
			'href',
			new RegExp(`/index\\.php/f/${folderId}$`),
		)
		await row.getByTestId('synaplan-watched-pause').click()
		await expect(row.getByTestId('synaplan-watched-resume')).toBeVisible()
		expect(stateOf(), 'paused in Synaplan').toBe('paused')
		await row.getByTestId('synaplan-watched-resume').click()
		await expect(row.getByTestId('synaplan-watched-pause')).toBeVisible()
		expect(stateOf(), 'watching again in Synaplan').toBe('active')

		await page.goto('/index.php/apps/files/')
		await page
			.locator(`[data-cy-files-list-row-name="${folder}"]`)
			.getByTestId('synaplan-row-bird')
			.click()
		const sidebar = page.getByTestId('synaplan-sidebar-tab')
		await sidebar.getByTestId('synaplan-sidebar-watch-pause').click()
		await expect(
			sidebar.getByTestId('synaplan-sidebar-watch-resume'),
		).toBeVisible()
		expect(stateOf(), 'paused from the sidebar').toBe('paused')

		await sidebar.getByTestId('synaplan-sidebar-watch-stop').click()
		const ask = page.getByRole('dialog', { name: 'Stop watching' })
		await expect(
			ask.getByText(
				`Synaplan stops watching ${folder}. Files and translations already made stay where they are.`,
			),
		).toBeVisible()
		await ask.getByRole('button', { name: 'Stop watching' }).click()
		await expect(sidebar.getByTestId('synaplan-sidebar-watch')).toHaveCount(0)
		expect(stateOf(), 'stopped in Synaplan').toBe('stopped')
		watchId = ''

		const listed = page.waitForResponse((r) =>
			r.url().endsWith('/api/v1/watches'),
		)
		await page.goto(`${APP}/research`)
		await listed
		await expect(
			page.getByTestId('synaplan-watched-row').filter({ hasText: folder }),
		).toHaveCount(0)
	} finally {
		if (watchId !== '') {
			synaplanSql(
				`UPDATE BCLOUDWATCHES SET BSTATE='stopped' WHERE BID=${watchId}`,
			)
		}
		await person.delete(dav)
		await person.dispose()
	}
})

/**
 * Share a folder in the owner's root with another person.
 *
 * @param owner API client signed in as the folder's owner
 * @param folder the folder name in the owner's root
 * @param uid who gets the share
 * @param permissions the share's permission bits
 */
async function shareWith(
	owner: APIRequestContext,
	folder: string,
	uid: string,
	permissions: number,
): Promise<void> {
	const shared = await owner.post(
		'/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json',
		{
			form: {
				path: `/${folder}`,
				shareType: '0',
				shareWith: uid,
				permissions: String(permissions),
			},
		},
	)
	expect(shared.ok(), `sharing ${folder} with ${uid}`).toBe(true)
}

/**
 * Upload a text file over WebDAV.
 *
 * @param person API client signed in as the person
 * @param path the WebDAV path
 * @param content the file's text
 */
function put(person: APIRequestContext, path: string, content: string) {
	return person.put(path, {
		data: content,
		headers: { 'Content-Type': 'text/plain' },
	})
}

/**
 * The Nextcloud file id of a file or folder.
 *
 * @param person API client signed in as the person
 * @param path the WebDAV path
 */
async function fileIdOf(person: APIRequestContext, path: string): Promise<string> {
	const found = await person.fetch(path, {
		method: 'PROPFIND',
		headers: { Depth: '0', 'Content-Type': 'application/xml' },
		data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	const id = /<oc:fileid>(\d+)<\/oc:fileid>/.exec(await found.text())?.[1] ?? ''
	expect(id, `the file id of ${path}`).toMatch(/^\d+$/)
	return id
}

/**
 * Run occ in the e2e Nextcloud.
 *
 * @param args the occ arguments
 */
function occ(...args: string[]): string {
	return execFileSync('docker', [
		'compose',
		'-p',
		NC_PROJECT,
		'-f',
		'docker-compose.ci.yml',
		'exec',
		'-T',
		'-u',
		'www-data',
		'nextcloud',
		'php',
		'occ',
		...args,
	]).toString()
}

/**
 * Run one of the app's background jobs now, as cron would.
 *
 * @param name the job class in OCA\SynaplanIntegration\BackgroundJob
 */
function runJob(name: string): void {
	const listed = JSON.parse(
		occ(
			'background-job:list',
			`--class=OCA\\SynaplanIntegration\\BackgroundJob\\${name}`,
			'--output=json',
		),
	) as Array<{ id: number | string }>
	expect(listed.length, `${name} is registered`).toBeGreaterThan(0)
	occ('background-job:execute', String(listed[0].id), '--force-execute')
}

/**
 * One value from the Synaplan test stack's database, or '' when the query
 * finds nothing.
 *
 * @param query the SQL
 */
function synaplanSql(query: string): string {
	return execFileSync('docker', [
		'exec',
		SYNAPLAN_DB_CONTAINER,
		'sh',
		'-c',
		'mariadb -N -B -uroot -p"$MYSQL_ROOT_PASSWORD" synaplan_test -e "$0"',
		query,
	])
		.toString()
		.trim()
}
