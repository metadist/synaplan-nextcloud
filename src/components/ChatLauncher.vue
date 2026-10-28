<template>
	<div
		class="synaplan-chat-launcher"
		:class="{ 'synaplan-chat-launcher--beside-sidebar': sidebarOffset > 0 }"
		:style="besideStyle">
		<!-- Chat window -->
		<div
			v-if="open"
			class="synaplan-chat-launcher-panel"
			role="dialog"
			:aria-label="t('synaplan_integration', 'Synaplan AI chat')">
			<div class="launcher-header">
				<span class="launcher-title">{{
					t('synaplan_integration', 'Synaplan AI')
				}}</span>
				<button
					class="launcher-close"
					:title="t('synaplan_integration', 'Close')"
					:aria-label="t('synaplan_integration', 'Close')"
					@click="open = false">
					×
				</button>
			</div>
			<div class="launcher-body">
				<ResearchChat />
			</div>
		</div>

		<!-- Floating launcher button (hidden while the window is open) -->
		<button
			v-if="!open"
			class="synaplan-chat-fab"
			:title="t('synaplan_integration', 'Chat with Synaplan AI')"
			:aria-label="t('synaplan_integration', 'Chat with Synaplan AI')"
			@click="open = true">
			<svg
				width="28"
				height="28"
				viewBox="0 0 24 24"
				fill="currentColor"
				aria-hidden="true">
				<path
					d="M12 3C6.48 3 2 6.86 2 11.5c0 2.3 1.1 4.38 2.9 5.9-.13 1.2-.6 2.5-1.5 3.6 1.7-.2 3.3-.8 4.6-1.8 1.2.4 2.6.6 4 .6 5.52 0 10-3.86 10-8.3S17.52 3 12 3z" />
			</svg>
		</button>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { filesSidebarOffset } from '../chat/filesSidebarOffset'
import ResearchChat from './ResearchChat.vue'

const open = ref(false)
const sidebarOffset = ref(0)

const besideStyle = computed(() =>
	sidebarOffset.value > 0
		? { '--synaplan-files-sidebar': `${sidebarOffset.value}px` }
		: undefined,
)

const SIDEBAR_ID = 'app-sidebar-vue'

let watched: HTMLElement | null = null
let bodyObserver: MutationObserver | null = null
let sidebarObserver: MutationObserver | null = null
let resizeObserver: ResizeObserver | null = null

/**
 * The Files sidebar stays mounted while closed. Re-measure whenever it
 * appears, or its visibility or width changes.
 */
function updateOffset(): void {
	const offset = filesSidebarOffset(document.getElementById(SIDEBAR_ID))
	if (offset !== sidebarOffset.value) {
		sidebarOffset.value = offset
	}
}

/**
 * @param {HTMLElement | null} sidebar The current sidebar element, or null
 */
function watchSidebar(sidebar: HTMLElement | null): void {
	if (sidebar === watched) {
		return
	}
	watched = sidebar
	sidebarObserver?.disconnect()
	resizeObserver?.disconnect()
	sidebarObserver = null
	resizeObserver = null
	if (!sidebar) {
		return
	}
	sidebarObserver = new MutationObserver(updateOffset)
	sidebarObserver.observe(sidebar, {
		attributes: true,
		attributeFilter: ['style', 'class', 'hidden'],
	})
	resizeObserver = new ResizeObserver(updateOffset)
	resizeObserver.observe(sidebar)
}

onMounted(() => {
	watchSidebar(document.getElementById(SIDEBAR_ID))
	updateOffset()
	bodyObserver = new MutationObserver(() => {
		const sidebar = document.getElementById(SIDEBAR_ID)
		if (sidebar === watched) {
			return
		}
		watchSidebar(sidebar)
		updateOffset()
	})
	bodyObserver.observe(document.body, { childList: true, subtree: true })
	window.addEventListener('resize', updateOffset)
})

onUnmounted(() => {
	window.removeEventListener('resize', updateOffset)
	bodyObserver?.disconnect()
	sidebarObserver?.disconnect()
	resizeObserver?.disconnect()
})
</script>

<style scoped>
.synaplan-chat-fab {
	position: fixed;
	right: 24px;
	bottom: 24px;
	width: 60px;
	height: 60px;
	border-radius: 50%;
	border: none;
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
	z-index: 10000;
	transition:
		transform 0.15s ease,
		background 0.15s ease,
		right 0.15s ease;
}

.synaplan-chat-fab:hover {
	transform: scale(1.06);
	background: var(--color-primary-element-hover, #0074b3);
}

.synaplan-chat-launcher-panel {
	position: fixed;
	right: 24px;
	bottom: 24px;
	width: min(460px, calc(100vw - 32px));
	height: min(750px, calc(100vh - 46px));
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border-dark, #c0c0c0);
	border-radius: 16px;
	box-shadow: 0 10px 48px rgba(0, 0, 0, 0.45);
	display: flex;
	flex-direction: column;
	overflow: hidden;
	z-index: 10000;
}

/* Only while the Files sidebar is open and a usable chat still fits beside it. */
.synaplan-chat-launcher--beside-sidebar .synaplan-chat-fab,
.synaplan-chat-launcher--beside-sidebar .synaplan-chat-launcher-panel {
	right: calc(24px + var(--synaplan-files-sidebar, 0px));
}

.synaplan-chat-launcher--beside-sidebar .synaplan-chat-launcher-panel {
	width: min(460px, calc(100vw - 48px - var(--synaplan-files-sidebar, 0px)));
}

/* High-contrast brand header */
.launcher-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 14px 18px;
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	flex-shrink: 0;
}

.launcher-title {
	font-weight: 700;
	font-size: 1.1em;
	color: inherit;
	letter-spacing: 0.01em;
}

.launcher-close {
	width: 34px;
	height: 34px;
	border: none;
	background: transparent;
	color: inherit;
	font-size: 26px;
	line-height: 1;
	border-radius: 8px;
	cursor: pointer;
	opacity: 0.92;
}

.launcher-close:hover {
	background: rgba(255, 255, 255, 0.18);
	opacity: 1;
}

.launcher-body {
	flex: 1;
	min-height: 0;
	overflow: hidden;
}

/* ---- Re-flow the embedded full-page ResearchChat into the compact panel ---- */
.launcher-body :deep(.synaplan-research-wrapper) {
	min-height: 0;
	height: 100%;
	padding: 0;
	align-items: stretch;
	box-sizing: border-box;
}

.launcher-body :deep(.synaplan-research) {
	max-width: none;
	width: 100%;
	height: 100%;
	max-height: none;
	border: none;
	border-radius: 0;
	box-shadow: none;
	/* extra bottom padding keeps the input clear of the rounded panel edge */
	padding: 14px 16px 16px;
	background: transparent;
	box-sizing: border-box;
}

/* The panel header already says "Synaplan AI" — drop the duplicate title. */
.launcher-body :deep(.research-header) {
	display: none;
}

/* The compact widget has limited room: the status chips (model/language/media)
   and the verbose memory hint are too much here. Hide them — the two selects
   plus the memory switch are enough. They stay visible on the full-page view. */
.launcher-body :deep(.status-row),
.launcher-body :deep(.memory-hint) {
	display: none;
}

/* Stack Knowledge + Model so the selects get full width (no overlap). */
.launcher-body :deep(.controls-bar) {
	flex-direction: column;
	gap: 5px;
	padding: 4px 0 12px;
	border-bottom: 2px solid var(--color-border-dark, #c0c0c0);
	margin-bottom: 10px;
}

.launcher-body :deep(.control-group) {
	width: 100%;
}

.launcher-body :deep(.control-label) {
	font-size: 0.78em;
	color: var(--color-main-text, #222);
	opacity: 0.85;
}

.launcher-body :deep(.control-select),
.launcher-body :deep(.control-select .v-select) {
	width: 100%;
}

/* Slightly larger, higher-contrast message bubbles + comfortable spacing. */
.launcher-body :deep(.messages) {
	gap: 10px;
	padding: 8px 0;
}

.launcher-body :deep(.message) {
	max-width: 90%;
	font-size: 0.95em;
	line-height: 1.55;
}

.launcher-body :deep(.message.assistant) {
	background: var(--color-background-dark, #e6e6e6);
	color: var(--color-main-text, #1a1a1a);
}

.launcher-body :deep(.empty-state) {
	font-size: 0.95em;
	color: var(--color-main-text, #444);
	opacity: 0.8;
	padding: 0 8px;
}

/* Input row: clear separation + breathing room for the Send button. */
.launcher-body :deep(.chat-input) {
	padding-top: 12px;
	gap: 8px;
	border-top: 2px solid var(--color-border-dark, #c0c0c0);
}

.launcher-body :deep(.chat-input .button-vue),
.launcher-body :deep(.chat-input button) {
	flex-shrink: 0;
}
</style>
