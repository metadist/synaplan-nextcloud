/** Width of the floating chat button. Matches `.synaplan-chat-fab` in ChatLauncher.vue. */
const FAB_SIZE_PX = 60

/** Gap between the button and the screen edge. Matches the button's `right`/`bottom`. */
const FAB_MARGIN_PX = 24

/** Room kept between the button and the opposite screen edge. */
const VIEWPORT_GUTTER_PX = 8

/**
 * Narrowest open chat that still shows its header and the question field.
 * Matches the `48px` of horizontal margin in the panel width rule.
 */
const PANEL_MIN_PX = 280
const PANEL_SIDE_GUTTER_PX = 48

/**
 * How far the chat button must move left so it sits beside the Files sidebar
 * instead of on top of it.
 *
 * The sidebar element stays in the page while it is closed (`display: none`),
 * so "it exists" is not "it is open". When the open chat would be narrower
 * than a usable panel, the button stays in the corner and draws above the
 * sidebar.
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
	const roomForButton =
		viewportWidth - FAB_SIZE_PX - FAB_MARGIN_PX - VIEWPORT_GUTTER_PX
	const roomForPanel = viewportWidth - PANEL_SIDE_GUTTER_PX - PANEL_MIN_PX
	const room = Math.min(roomForButton, roomForPanel)
	if (room <= 0 || width > room) {
		return 0
	}
	return Math.ceil(width)
}
