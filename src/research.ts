import { createApp } from 'vue'
import ResearchChat from './components/ResearchChat.vue'

/**
 * Inject global styles for the research chat page.
 *
 * Scoped CSS may not reliably override Nextcloud's theme variables
 * (e.g. --color-main-background can be semi-transparent in dark mode).
 * We inject explicit styles to guarantee the card appearance.
 */
function injectResearchStyles(): void {
	const css = `
#synaplan-research-chat .synaplan-research-wrapper {
	display: flex;
	align-items: flex-start;
	justify-content: center;
	min-height: calc(100vh - 50px);
	padding: 32px 24px;
	box-sizing: border-box;
}

#synaplan-research-chat .synaplan-research {
	display: flex;
	flex-direction: column;
	width: 100%;
	max-width: 800px;
	height: calc(100vh - 110px);
	max-height: 900px;
	background: rgba(0, 0, 0, 0.55);
	backdrop-filter: blur(24px);
	-webkit-backdrop-filter: blur(24px);
	border: 1px solid rgba(255, 255, 255, 0.15);
	border-radius: 16px;
	box-shadow: 0 8px 40px rgba(0, 0, 0, 0.4);
	padding: 28px 28px 20px;
	overflow: hidden;
}

#synaplan-research-chat .research-header {
	text-align: center;
	margin-bottom: 16px;
	flex-shrink: 0;
}

#synaplan-research-chat .research-header h2 {
	margin: 0;
	font-size: 1.4em;
	font-weight: 700;
}

#synaplan-research-chat .subtitle {
	color: var(--color-text-maxcontrast, #999);
	margin: 6px 0 0;
	font-size: 0.9em;
}

#synaplan-research-chat .messages {
	flex: 1;
	overflow-y: auto;
	padding: 12px 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 0;
}

#synaplan-research-chat .empty-state {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
	color: var(--color-text-maxcontrast, #999);
	font-size: 1.05em;
}

#synaplan-research-chat .message {
	max-width: 80%;
	padding: 12px 16px;
	border-radius: 12px;
	line-height: 1.6;
}

#synaplan-research-chat .message.user {
	align-self: flex-end;
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}

#synaplan-research-chat .message.assistant {
	align-self: flex-start;
	background: var(--color-background-dark, rgba(255, 255, 255, 0.07));
}

#synaplan-research-chat .message-content {
	white-space: pre-wrap;
	word-break: break-word;
}

#synaplan-research-chat .loading-dots {
	opacity: 0.7;
	font-style: italic;
}

#synaplan-research-chat .chat-input {
	display: flex;
	gap: 10px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border, rgba(255, 255, 255, 0.12));
	flex-shrink: 0;
	align-items: center;
}

#synaplan-research-chat .chat-input .input-field {
	flex: 1;
	min-width: 0;
}

#synaplan-research-chat .chat-input input {
	width: 100% !important;
}
`
	const style = document.createElement('style')
	style.setAttribute('data-source', 'synaplan-research-styles')
	style.textContent = css
	document.head.appendChild(style)
}

injectResearchStyles()

const el = document.getElementById('synaplan-research-chat')
if (el) {
	const app = createApp(ResearchChat)
	app.mount(el)
}
