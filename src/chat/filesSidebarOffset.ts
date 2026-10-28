/** Width of the floating chat button. Matches `.synaplan-chat-fab` in ChatLauncher.vue. */
const FAB_SIZE_PX = 60

/** Gap between the button and the screen edge. Matches the button's `right`/`bottom`. */
const FAB_MARGIN_PX = 24

/** Room kept between the button and the opposite screen edge. */
const VIEWPORT_GUTTER_PX = 8

/**
 * How far the chat button must move left so it sits beside the Files sidebar
 * instead of on top of it.
 *
 * The sidebar element stays in the page while it is closed (`display: none`),
 * so "it exists" is not "it is open". A full-width sidebar (a narrow window)
 * leaves no room beside it; the button then stays in the corner.
 *
 * @param sidebar The Files sidebar element, or null when Files has not mounted it
 * @param viewportWidth Width of the window the button is positioned against
 */
export function filesSidebarOffset(
	sidebar: Element | null,
	viewportWidth: number = window.innerWidth,
): number {
	if (!sidebar) {
		return 0
	}
	if (getComputedStyle(sidebar).display === 'none') {
		return 0
	}
	const width = sidebar.getBoundingClientRect().width
	if (width <= 0) {
		return 0
	}
	const room = viewportWidth - FAB_SIZE_PX - FAB_MARGIN_PX - VIEWPORT_GUTTER_PX
	if (room <= 0 || width > room) {
		return 0
	}
	return Math.ceil(width)
}
