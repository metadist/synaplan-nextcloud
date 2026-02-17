declare module '*.vue' {
	import type { DefineComponent } from 'vue'

	const component: DefineComponent<object, object, unknown>
	export default component
}

// Nextcloud vue components don't always have proper types
declare module '@nextcloud/vue/components/*' {
	import type { DefineComponent } from 'vue'

	const component: DefineComponent<object, object, unknown>
	export default component
}
