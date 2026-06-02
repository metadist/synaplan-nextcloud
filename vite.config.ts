import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	settings: 'src/settings.ts',
	'files-init': 'src/files-init.ts',
	research: 'src/research.ts',
	'chat-launcher': 'src/chat-launcher.ts',
}, {
	inlineCSS: true,
})
