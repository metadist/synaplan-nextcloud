/**
 * Fix for NcModal/NcDialog scoped CSS mismatch.
 *
 * When a Nextcloud app bundles its own @nextcloud/vue via Vite, the scoped
 * data-v-* attribute on NcModal may differ from the one in the server-side CSS.
 * This causes the modal overlay and centering styles to not apply.
 *
 * These unscoped rules provide the essential modal functionality regardless
 * of which @nextcloud/vue version the server ships.
 */

const MODAL_CSS = `
.modal-mask {
	position: fixed;
	z-index: 9998;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background-color: rgba(0, 0, 0, 0.5);
	display: block;
}

.modal-mask,
.modal-mask * {
	box-sizing: border-box;
}

.modal-wrapper {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
}

.modal-container {
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

.modal-container--normal {
	max-width: 600px;
}

.modal-container--small {
	max-width: 400px;
}

.modal-container__close {
	position: absolute;
	right: 4px;
	top: 4px;
	z-index: 1;
}

.dialog__wrapper {
	display: flex;
	flex-direction: column;
}

.dialog__content {
	padding: 12px 20px;
	flex: 1 1 auto;
	overflow: auto;
}

.dialog__name {
	text-align: center;
	padding: 12px 20px 0;
	margin: 0;
}

.dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	padding: 8px 20px 12px;
}
`

let injected = false

export function injectModalStyles(): void {
	if (injected) return
	injected = true

	const style = document.createElement('style')
	style.setAttribute('data-source', 'synaplan-modal-fix')
	style.textContent = MODAL_CSS
	document.head.appendChild(style)
}
