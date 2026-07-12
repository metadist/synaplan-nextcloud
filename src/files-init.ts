import { registerFileAction } from '@nextcloud/files'
import type { IFileAction } from '@nextcloud/files'
import { summarizeAction } from './files_actions/summarizeAction'
import { translateAction } from './files_actions/translateAction'
import { chatAction } from './files_actions/chatAction'
import { chatFileAction } from './files_actions/chatFileAction'
import { injectModalStyles } from './styles/modal-fix'

/**
 * Register a file action in both the v4 scoped registry and the legacy
 * _nc_fileactions array. NC34 dev builds ship @nextcloud/files RC which
 * stores actions in window._nc_fileactions, while stable v4.0.0 uses
 * window._nc_files_scope.v4_0. Dual registration ensures compatibility.
 * @param action
 */
function registerAction(action: IFileAction): void {
	registerFileAction(action)

	const w = window as unknown as Record<string, unknown>
	if (typeof w._nc_fileactions === 'undefined') {
		w._nc_fileactions = []
	}
	const legacy = w._nc_fileactions as Array<{ id: string }>
	if (!legacy.find((a) => a.id === action.id)) {
		legacy.push(action as unknown as { id: string })
	}
}

injectModalStyles()
injectFormStyles()

registerAction(summarizeAction)
registerAction(translateAction)
registerAction(chatAction)
registerAction(chatFileAction)

/**
 *
 */
function injectFormStyles(): void {
	const css = `
.synaplan-summary-modal .options,
.synaplan-translate-modal .options {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.synaplan-summary-modal .field,
.synaplan-translate-modal .field {
	display: flex;
	align-items: center;
	gap: 12px;
}

.synaplan-summary-modal .field label,
.synaplan-translate-modal .field label {
	flex: 0 0 150px;
	font-weight: bold;
	text-align: right;
	white-space: nowrap;
	overflow: visible;
}

.synaplan-summary-modal .field .v-select,
.synaplan-translate-modal .field .v-select {
	flex: 1;
	min-width: 0;
}

.synaplan-summary-modal,
.synaplan-translate-modal {
	padding: 16px 0;
	min-height: 120px;
}

.synaplan-summary-modal .loading,
.synaplan-translate-modal .loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 24px;
}

.synaplan-chat-modal {
	display: flex;
	flex-direction: column;
	min-height: 300px;
	max-height: 500px;
}

.synaplan-chat-modal .messages {
	flex: 1;
	overflow-y: auto;
	padding: 8px 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-height: 0;
}

.synaplan-chat-modal .message {
	max-width: 85%;
	padding: 10px 14px;
	border-radius: 12px;
	line-height: 1.5;
}

.synaplan-chat-modal .message.user {
	align-self: flex-end;
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}

.synaplan-chat-modal .message.assistant {
	align-self: flex-start;
	background: var(--color-background-dark, #2a2a2a);
}

.synaplan-chat-modal .message-content {
	white-space: pre-wrap;
	word-break: break-word;
}

.synaplan-chat-modal .chat-input {
	display: flex;
	gap: 10px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border, #444);
	margin-top: 8px;
	align-items: center;
}

.synaplan-chat-modal .chat-input .input-field {
	flex: 1;
	min-width: 0;
}

.synaplan-chat-modal .chat-input input {
	width: 100% !important;
}

/* Knowledge modal styles */
.synaplan-knowledge-modal {
	padding: 8px 0;
}

.synaplan-knowledge-modal .description {
	color: var(--color-text-maxcontrast, #767676);
	margin: 0 0 16px;
	line-height: 1.5;
}

.synaplan-knowledge-modal .field {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.synaplan-knowledge-modal .field-label {
	flex: 0 0 140px;
	font-weight: 600;
	color: var(--color-main-text, #222);
}

.synaplan-knowledge-modal .field .v-select {
	flex: 1;
	min-width: 0;
}

.synaplan-knowledge-modal .file-info {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 14px;
	background: var(--color-background-dark, #f5f5f5);
	border-radius: 8px;
	margin-top: 8px;
}

.synaplan-knowledge-modal .success-state {
	text-align: center;
	padding: 24px 0;
}

.synaplan-knowledge-modal .success-icon {
	font-size: 3em;
	color: var(--color-success, #46ba61);
	margin-bottom: 12px;
}

.synaplan-knowledge-modal .success-text {
	font-size: 1.15em;
	font-weight: 600;
	margin: 0 0 20px;
}

.synaplan-knowledge-modal .success-details {
	text-align: left;
	max-width: 300px;
	margin: 0 auto;
}

.synaplan-knowledge-modal .detail-row {
	display: flex;
	justify-content: space-between;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border-dark, #e0e0e0);
}

.synaplan-knowledge-modal .detail-label {
	color: var(--color-text-maxcontrast, #767676);
}

.synaplan-knowledge-modal .detail-value {
	font-weight: 600;
}
`
	const style = document.createElement('style')
	style.setAttribute('data-source', 'synaplan-form-styles')
	style.textContent = css
	document.head.appendChild(style)
}
