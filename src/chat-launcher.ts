import { createApp } from 'vue'
import ChatLauncher from './components/ChatLauncher.vue'

/**
 * Mounts the floating Synaplan chat launcher (a bottom-right button that opens
 * an in-page chat window) on any Nextcloud page. Loaded globally from
 * Application::boot(), independent of the per-file context actions and the
 * full-page /research view.
 */
function mountChatLauncher(): void {
	// Avoid double-mount if the script is included more than once.
	if (document.getElementById('synaplan-chat-launcher')) {
		return
	}
	const el = document.createElement('div')
	el.id = 'synaplan-chat-launcher'
	document.body.appendChild(el)
	createApp(ChatLauncher).mount(el)
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mountChatLauncher)
} else {
	mountChatLauncher()
}
