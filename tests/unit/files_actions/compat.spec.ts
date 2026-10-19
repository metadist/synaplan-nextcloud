import { describe, expect, it } from 'vitest'

import { extractNodeFromExec, extractNodesFromEnabled } from '@/files_actions/compat'

const node = {
	fileid: 7,
	basename: 'report.pdf',
	mime: 'application/pdf',
	permissions: 1,
}

describe('extractNodesFromEnabled', () => {
	it('reads the v4 shape ({ nodes })', () => {
		expect(extractNodesFromEnabled({ nodes: [node] })).toEqual([node])
	})

	it('reads the v3 shape (nodes, view)', () => {
		expect(extractNodesFromEnabled([node], {})).toEqual([node])
	})

	it('returns no nodes for anything else', () => {
		expect(extractNodesFromEnabled()).toEqual([])
		expect(extractNodesFromEnabled({})).toEqual([])
	})
})

describe('extractNodeFromExec', () => {
	it('reads the v4 shape ({ nodes })', () => {
		expect(extractNodeFromExec({ nodes: [node] })).toBe(node)
	})

	it('reads the v3 shape (node, view, dir)', () => {
		expect(extractNodeFromExec(node, {}, '/')).toBe(node)
	})

	it('returns null without a node', () => {
		expect(extractNodeFromExec({ nodes: [] })).toBeNull()
		expect(extractNodeFromExec()).toBeNull()
	})
})
