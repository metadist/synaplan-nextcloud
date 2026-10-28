import { afterEach, describe, expect, it } from 'vitest'

import { filesSidebarOffset } from '@/chat/filesSidebarOffset'

const VIEWPORT = 1200

/**
 * An element whose layout box we control. happy-dom does not run real layout,
 * so the box width is stubbed the way the browser would report it.
 *
 * @param display The computed display value
 * @param width Reported border-box width
 */
function sidebar(display: string, width: number): HTMLElement {
	const element = document.createElement('aside')
	element.style.display = display
	document.body.appendChild(element)
	element.getBoundingClientRect = () =>
		({
			x: 0,
			y: 0,
			top: 0,
			left: 0,
			right: width,
			bottom: 0,
			width,
			height: 400,
			toJSON() {
				return {}
			},
		}) as DOMRect
	return element
}

describe('filesSidebarOffset', () => {
	afterEach(() => {
		document.body.replaceChildren()
	})
	it('stays put when Files has no sidebar', () => {
		expect(filesSidebarOffset(null, VIEWPORT)).toBe(0)
	})

	it('stays put while the sidebar element is hidden', () => {
		expect(filesSidebarOffset(sidebar('none', 400), VIEWPORT)).toBe(0)
	})

	it('stays put when the open sidebar has no width yet', () => {
		expect(filesSidebarOffset(sidebar('flex', 0), VIEWPORT)).toBe(0)
	})

	it('moves left by the open sidebar width', () => {
		expect(filesSidebarOffset(sidebar('flex', 360.2), VIEWPORT)).toBe(361)
	})

	it('stays in the corner when the sidebar covers the window', () => {
		expect(filesSidebarOffset(sidebar('flex', 1180), VIEWPORT)).toBe(0)
	})
})
