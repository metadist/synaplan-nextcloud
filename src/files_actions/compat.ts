/**
 * Compatibility layer for @nextcloud/files v3 (NC30-32) and v4 (NC33+).
 *
 * v3 callbacks: enabled(nodes: Node[], view) / exec(node: Node, view, dir)
 * v4 callbacks: enabled({ nodes }) / exec({ nodes })
 *
 * NC34 dev from git may use an RC build with the v3-style _nc_fileactions
 * global but v4-style callback signatures, so we handle all combinations.
 */

/* eslint-disable @typescript-eslint/no-explicit-any */

export interface NodeCompat {
	fileid: number
	basename: string
	mime?: string
	permissions: number
}

/**
 *
 * @param {...any} args
 */
export function extractNodesFromEnabled(...args: any[]): NodeCompat[] {
	const first = args[0]
	if (first?.nodes) return first.nodes
	if (Array.isArray(first)) return first
	return []
}

/**
 *
 * @param {...any} args
 */
export function extractNodeFromExec(...args: any[]): NodeCompat | null {
	const first = args[0]
	if (first?.nodes?.[0]) return first.nodes[0]
	if (first?.fileid !== undefined) return first
	return null
}
