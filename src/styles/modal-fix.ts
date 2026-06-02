/**
 * Fix for NcModal/NcDialog scoped CSS mismatch.
 *
 * When a Nextcloud app bundles its own @nextcloud/vue via Vite, the scoped
 * data-v-* attribute on NcModal may differ from the one in the server-side CSS.
 * This causes the modal overlay and centering styles to not apply.
 *
 * These rules provide the essential modal functionality regardless of which
 * @nextcloud/vue version the server ships — but they MUST only target the
 * plugin's OWN modals. Earlier they were global, which re-styled (and boxed)
 * Nextcloud's own modals too — most visibly the Viewer modal that hosts the
 * Collabora/Office editor, capping it at max-width: 900px. Every rule below is
 * therefore scoped via `:has()` to a modal that contains one of our modal
 * bodies, so the Office editor (and any other NC modal) is left full-width.
 */

// Content classes rendered inside our own modals (see files-init.ts styles).
const PLUGIN_MODAL_BODIES = [
	'.synaplan-summary-modal',
	'.synaplan-translate-modal',
	'.synaplan-chat-modal',
	'.synaplan-knowledge-modal',
]

/**
 * Build a selector list scoping `suffix` to our own modals only.
 * @param suffix e.g. '' for the mask itself, or ' .modal-container'
 */
function ours(suffix: string): string {
	return PLUGIN_MODAL_BODIES.map((b) => `.modal-mask:has(${b})${suffix}`).join(
		',\n',
	)
}

const MODAL_CSS = `
${ours('')} {
	position: fixed;
	z-index: 9998;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background-color: rgba(0, 0, 0, 0.5);
	display: block;
}

${ours('')},
${ours(' *')} {
	box-sizing: border-box;
}

${ours(' .modal-wrapper')} {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
}

${ours(' .modal-container')} {
	background: var(--color-main-background, #fff);
	border-radius: var(--border-radius-large, 10px);
	padding: 0;
	margin: 20px;
	max-height: calc(100vh - 40px);
	max-width: 900px;
	width: 100%;
	overflow: auto;
	box-shadow: 0 2px 20px rgba(0, 0, 0, 0.3);
}

${ours(' .modal-container--normal')} {
	max-width: 600px;
}

${ours(' .modal-container--small')} {
	max-width: 400px;
}

${ours(' .modal-container__close')} {
	position: absolute;
	right: 4px;
	top: 4px;
	z-index: 1;
}

${ours(' .dialog__wrapper')} {
	display: flex;
	flex-direction: column;
}

${ours(' .dialog__content')} {
	padding: 12px 20px;
	flex: 1 1 auto;
	overflow: auto;
}

${ours(' .dialog__name')} {
	text-align: center;
	padding: 12px 20px 0;
	margin: 0;
}

${ours(' .dialog__actions')} {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	padding: 8px 20px 12px;
}
`

let injected = false

/**
 * Inject the scoped modal styles once.
 */
export function injectModalStyles(): void {
	if (injected) return
	injected = true

	const style = document.createElement('style')
	style.setAttribute('data-source', 'synaplan-modal-fix')
	style.textContent = MODAL_CSS
	document.head.appendChild(style)
}
