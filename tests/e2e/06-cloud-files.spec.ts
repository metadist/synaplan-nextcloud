import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import {
	ensureConnected,
	linksOf,
	logIn,
	nextcloudAs,
	SYNAPLAN_PERSON,
	synaplanAs,
	APP,
} from './support'

/**
 * Copies and folder reads between Synaplan and Nextcloud. Synaplan only
 * sends a signed notice naming the person; the app claims, downloads, lists,
 * uploads and reports with that person's own key, never with the instance
 * credential. The person connects once from the personal settings.
 *
 * Synaplan opens a folder read only from a task's folder step, which needs a
 * planning model; its dev/test command `app:cloud-pickups:open-test` opens
 * the same request without one.
 */

const UID = 'ada'
const SYNAPLAN_CONTAINER = process.env.SYNAPLAN_APP_CONTAINER ?? 'synaplan-app-test'

test.describe.configure({ mode: 'serial' })

test.beforeEach(async ({ page }) => {
	await logIn(page, UID)
	await ensureConnected(page)
})

test('a copy from Synaplan lands in Synaplan/Documents of the person it names', async () => {
	const name = `copy-${Date.now()}.txt`
	const synaplan = await synaplanAs(SYNAPLAN_PERSON)
	const uploaded = await synaplan.post('/api/v1/files/upload', {
		multipart: {
			'files[]': { name, mimeType: 'text/plain', buffer: Buffer.from(`copy of ${name}`) },
			process_level: 'extract',
		},
	})
	expect(uploaded.ok(), 'uploading to Synaplan').toBe(true)
	const fileId = ((await uploaded.json()) as { files: Array<{ id: number }> }).files[0].id
	const [link] = await linksOf(synaplan, UID)
	expect(link, 'the person is linked from this Nextcloud').toBeTruthy()

	const sent = await synaplan.post(`/api/v1/files/${fileId}/send`, {
		data: { destination: 'platform', identity_id: link.id },
	})
	const body = (await sent.json()) as { reference?: string, context?: { state?: string } }
	expect(sent.status(), JSON.stringify(body)).toBe(200)
	expect(body.context?.state).toBe('delivered')
	expect(body.reference).toBe(`/Synaplan/Documents/${name}`)

	const person = await nextcloudAs(UID)
	const copy = await person.get(`/remote.php/dav/files/${UID}/Synaplan/Documents/${name}`)
	expect(await copy.text()).toBe(`copy of ${name}`)
	await person.delete(`/remote.php/dav/files/${UID}/Synaplan/Documents/${name}`)
	await synaplan.delete(`/api/v1/files/${fileId}`)
	await person.dispose()
	await synaplan.dispose()
})

test('Synaplan reads a folder by path only when the person allows it', async () => {
	const folder = `Invoices-${Date.now()}`
	const person = await nextcloudAs(UID)
	expect((await person.fetch(`/remote.php/dav/files/${UID}/${folder}`, { method: 'MKCOL' })).ok()).toBe(true)
	for (const file of ['invoice-1.txt', 'invoice-2.txt']) {
		const put = await person.put(`/remote.php/dav/files/${UID}/${folder}/${file}`, {
			data: `${file} content`,
			headers: { 'Content-Type': 'text/plain' },
		})
		expect(put.ok(), `uploading ${file}`).toBe(true)
	}
	const setScope = async (mode: string) => {
		const saved = await person.put(`${APP}/api/v1/preferences`, {
			data: { read_scope: { mode, folders: [] } },
		})
		expect(saved.ok(), `saving the read setting ${mode}`).toBe(true)
	}

	try {
		await setScope('all')
		const allowed = openFolderRead(folder)
		expect(allowed.state, JSON.stringify(allowed)).toBe('done')
		expect(allowed.sent).toBe(2)
		expect([...allowed.names].sort()).toEqual(['invoice-1.txt', 'invoice-2.txt'])

		await setScope('watched')
		const refused = openFolderRead(folder)
		expect(refused.sent ?? 0).toBe(0)
		expect(['refused', 'failed']).toContain(refused.state)
	} finally {
		await setScope('watched')
		await person.delete(`/remote.php/dav/files/${UID}/${folder}`)
		await person.dispose()
	}
})

/**
 * Open a folder read in Synaplan for the Synaplan person linked to Ada.
 *
 * @param folder the folder path in Ada's files
 */
function openFolderRead(folder: string): { state: string, sent: number, names: string[] } {
	let out: string
	try {
		out = execFileSync('docker', [
			'exec',
			SYNAPLAN_CONTAINER,
			'php',
			'bin/console',
			'app:cloud-pickups:open-test',
			`--email=${SYNAPLAN_PERSON.email}`,
			'--client=nextcloud',
			`--folder=${folder}`,
			'--mode=fetch',
		]).toString()
	} catch (e) {
		// A refused read exits non-zero and still prints its JSON line.
		out = String((e as { stdout?: Buffer }).stdout ?? '')
	}
	return JSON.parse(out.trim().split('\n').pop() || '{}')
}
