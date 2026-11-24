import { expect, test } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import {
	APP,
	ensureConnected,
	linksOf,
	logIn,
	NC_URL,
	nextcloudAs,
	saveSettings,
	SYNAPLAN_CONTAINER,
	SYNAPLAN_PERSON,
	synaplanAs,
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
			'files[]': {
				name,
				mimeType: 'text/plain',
				buffer: Buffer.from(`copy of ${name}`),
			},
			process_level: 'extract',
		},
	})
	expect(uploaded.ok(), 'uploading to Synaplan').toBe(true)
	const fileId = ((await uploaded.json()) as { files: Array<{ id: number }> })
		.files[0].id
	const [link] = await linksOf(synaplan, UID)
	expect(link, 'the person is linked from this Nextcloud').toBeTruthy()

	const sent = await synaplan.post(`/api/v1/files/${fileId}/send`, {
		data: { destination: 'platform', identity_id: link.id },
	})
	const body = (await sent.json()) as {
		reference?: string
		context?: { state?: string; open_url?: string }
	}
	expect(sent.status(), JSON.stringify(body)).toBe(200)
	expect(body.context?.state).toBe('delivered')
	expect(
		new URL(body.context?.open_url ?? 'about:blank').origin,
		'the copy opens where people reach this Nextcloud',
	).toBe(new URL(NC_URL).origin)
	expect(body.reference).toBe(`/Synaplan/Documents/${name}`)

	const person = await nextcloudAs(UID)
	const copy = await person.get(
		`/remote.php/dav/files/${UID}/Synaplan/Documents/${name}`,
	)
	expect(await copy.text()).toBe(`copy of ${name}`)
	await person.delete(`/remote.php/dav/files/${UID}/Synaplan/Documents/${name}`)
	await synaplan.delete(`/api/v1/files/${fileId}`)
	await person.dispose()
	await synaplan.dispose()
})

test('Synaplan reads a folder by path only when the person allows it', async () => {
	const folder = `Invoices-${Date.now()}`
	const person = await nextcloudAs(UID)
	expect(
		(
			await person.fetch(`/remote.php/dav/files/${UID}/${folder}`, {
				method: 'MKCOL',
			})
		).ok(),
	).toBe(true)
	for (const file of ['invoice-1.txt', 'invoice-2.txt']) {
		const put = await person.put(
			`/remote.php/dav/files/${UID}/${folder}/${file}`,
			{
				data: `${file} content`,
				headers: { 'Content-Type': 'text/plain' },
			},
		)
		expect(put.ok(), `uploading ${file}`).toBe(true)
	}
	const setScope = async (mode: string): Promise<string> => {
		const saved = await person.put(`${APP}/api/v1/preferences`, {
			data: { read_scope: { mode, folders: [] } },
		})
		expect(saved.ok(), `saving the read setting ${mode}`).toBe(true)
		return ((await saved.json()) as { read_scope: { mode: string } }).read_scope
			.mode
	}

	try {
		expect(await setScope('all'), 'all files above the ceiling').toBe('allowed')
		const capped = openFolderRead(folder)
		expect(capped.sent ?? 0).toBe(0)
		expect(['refused', 'failed']).toContain(capped.state)

		await saveSettings({ read_scope_ceiling: 'all' })
		expect(await setScope('all')).toBe('all')
		const allowed = openFolderRead(folder)
		expect(allowed.state, JSON.stringify(allowed)).toBe('done')
		expect(allowed.sent).toBe(2)
		expect([...allowed.names].sort()).toEqual(['invoice-1.txt', 'invoice-2.txt'])

		await setScope('watched')
		const refused = openFolderRead(folder)
		expect(refused.sent ?? 0).toBe(0)
		expect(['refused', 'failed']).toContain(refused.state)
	} finally {
		await saveSettings({ read_scope_ceiling: 'allowed' })
		await setScope('watched')
		await person.delete(`/remote.php/dav/files/${UID}/${folder}`)
		await person.dispose()
	}
})

test('the read setting shows every choice with its folders and warning, whichever is picked', async ({
	page,
}) => {
	const folder = `Contracts-${Date.now()}`
	const person = await nextcloudAs(UID)
	const dav = `/remote.php/dav/files/${UID}/${folder}`
	expect((await person.fetch(dav, { method: 'MKCOL' })).ok()).toBe(true)
	try {
		await saveSettings({ read_scope_ceiling: 'all' })
		const saved = await person.put(`${APP}/api/v1/preferences`, {
			data: {
				read_scope: { mode: 'watched', folders: [{ id: '', path: folder }] },
			},
		})
		expect(saved.ok(), 'saving "Only what I watch" with a named folder').toBe(
			true,
		)

		await page.goto('/index.php/settings/user/synaplan_integration')
		await expect(page.getByText(folder, { exact: true })).toBeVisible()
		await expect(page.getByText('Add a folder', { exact: true })).toBeVisible()
		await expect(
			page.getByText('Synaplan can read every file you can open.'),
		).toBeVisible()
		await expect(
			page.getByText(
				'Right-click any folder → "Let Synaplan read this folder" adds it here.',
			),
		).toBeVisible()
	} finally {
		await saveSettings({ read_scope_ceiling: 'allowed' })
		await person.put(`${APP}/api/v1/preferences`, {
			data: { read_scope: { mode: 'watched', folders: [] } },
		})
		await person.delete(dav)
		await person.dispose()
	}
})

test('an allowed folder can still be read by Synaplan after it is moved', async () => {
	const stamp = Date.now()
	const folder = `Receipts-${stamp}`
	const moved = `Archive-${stamp}/Receipts 2026`
	const person = await nextcloudAs(UID)
	const dav = `/remote.php/dav/files/${UID}`
	for (const path of [folder, `Archive-${stamp}`]) {
		expect(
			(await person.fetch(`${dav}/${path}`, { method: 'MKCOL' })).ok(),
		).toBe(true)
	}
	expect(
		(
			await person.put(`${dav}/${folder}/receipt.txt`, {
				data: 'receipt',
				headers: { 'Content-Type': 'text/plain' },
			})
		).ok(),
	).toBe(true)

	try {
		const saved = await person.put(`${APP}/api/v1/preferences`, {
			data: {
				read_scope: { mode: 'allowed', folders: [{ id: '', path: folder }] },
			},
		})
		expect(saved.ok(), 'allowing the folder').toBe(true)
		expect(openFolderRead(folder).state).toBe('done')

		const move = await person.fetch(`${dav}/${folder}`, {
			method: 'MOVE',
			headers: {
				Destination: `${NC_URL}${dav}/${encodeURI(moved)}`,
			},
		})
		expect(move.ok(), 'moving the folder').toBe(true)

		const read = openFolderRead(moved)
		expect(read.state, JSON.stringify(read)).toBe('done')
		expect(read.names).toEqual(['receipt.txt'])
		expect(openFolderRead(folder).sent ?? 0).toBe(0)
	} finally {
		await person.put(`${APP}/api/v1/preferences`, {
			data: { read_scope: { mode: 'watched', folders: [] } },
		})
		await person.delete(`${dav}/Archive-${stamp}`)
		await person.delete(`${dav}/${folder}`)
		await person.dispose()
	}
})

test('New → From Synaplan… adds the picked files to the open folder', async ({
	page,
}) => {
	const stamp = Date.now()
	const folder = `Q3-${stamp}`
	const notes = `notes-${stamp}.txt`
	const person = await nextcloudAs(UID)
	const synaplan = await synaplanAs(SYNAPLAN_PERSON)
	const dav = `/remote.php/dav/files/${UID}/${folder}`
	expect((await person.fetch(dav, { method: 'MKCOL' })).ok()).toBe(true)
	const synaplanIds: number[] = []
	let chartName = ''
	try {
		const uploaded = await synaplan.post('/api/v1/files/upload', {
			multipart: {
				'files[]': {
					name: notes,
					mimeType: 'text/plain',
					buffer: Buffer.from(`notes ${stamp}`),
				},
				process_level: 'extract',
			},
		})
		expect(uploaded.ok(), 'uploading to Synaplan').toBe(true)
		synaplanIds.push(
			((await uploaded.json()) as { files: Array<{ id: number }> }).files[0]
				.id,
		)
		const generated = await synaplan.post('/api/v1/media/generate', {
			data: { prompt: `A chart ${stamp}`, type: 'image' },
			timeout: 120_000,
		})
		const made = (await generated.json()) as { file?: { url?: string } }
		expect(generated.ok(), JSON.stringify(made)).toBe(true)
		chartName = (made.file?.url ?? '').split('/').pop() ?? ''
		expect(chartName, JSON.stringify(made)).not.toBe('')

		await page.goto(`/index.php/apps/files/files?dir=/${folder}`)
		await page.getByRole('button', { name: 'New' }).first().click()
		await page.getByRole('menuitem', { name: 'From Synaplan…' }).click()
		const picker = page.getByRole('dialog', { name: 'Add from Synaplan' })
		await expect(picker.getByText(`Saved into: ${folder}`)).toBeVisible()
		await expect(
			picker.getByRole('button', { name: 'Add files' }),
		).toBeDisabled()
		const chart = picker.locator('li', { hasText: chartName })
		await expect(chart).toContainText('Generated')
		await expect(picker.locator('li', { hasText: notes })).toHaveCount(0)
		await chart.getByText(chartName).click()

		await picker.getByRole('combobox').click()
		await page.getByRole('option', { name: 'All files' }).click()
		await picker.getByRole('searchbox').fill(notes)
		await expect(picker.locator('li')).toHaveCount(1)
		await picker.locator('li', { hasText: notes }).getByText(notes).click()
		await picker.getByRole('button', { name: 'Add 2 files' }).click()
		await expect(picker).toHaveCount(0)

		for (const name of [chartName, notes]) {
			await expect(
				page.locator(`[data-cy-files-list-row-name="${name}"]`),
				`${name} shows in the folder without a reload`,
			).toBeVisible()
		}
		expect(await (await person.get(`${dav}/${notes}`)).text()).toBe(
			`notes ${stamp}`,
		)
	} finally {
		await person.delete(dav)
		if (chartName !== '') {
			const listed = await synaplan.get('/api/v1/files', {
				params: { source: 'generated', search: chartName },
			})
			const rows = ((await listed.json()) as { files?: Array<{ id: number }> })
				.files
			synaplanIds.push(...(rows ?? []).map((row) => row.id))
		}
		for (const id of synaplanIds) {
			await synaplan.delete(`/api/v1/files/${id}`)
		}
		await person.dispose()
		await synaplan.dispose()
	}
})

/**
 * Open a folder read in Synaplan for the Synaplan person linked to Ada.
 *
 * @param folder the folder path in Ada's files
 */
function openFolderRead(folder: string): {
	state: string
	sent: number
	names: string[]
} {
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
