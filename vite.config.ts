import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	settings: 'src/settings.ts',
	'files-init': 'src/files-init.ts',
	research: 'src/research.ts',
	'chat-launcher': 'src/chat-launcher.ts',
}, {
	// `relativeCSSInjection` makes every chunk inject its OWN styles. Without it,
	// CSS shared across entries (e.g. NcSelect / NcCheckboxRadioSwitch) is only
	// injected by a single entry (settings), leaving the research/launcher/files
	// entries unstyled. With it, each entry that imports the shared chunk gets the
	// styles. See: multi-entry + inlineCSS shared-CSS gotcha.
	inlineCSS: { relativeCSSInjection: true },
})
