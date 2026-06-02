<template>
	<div class="synaplan-chat-launcher">
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

		<!-- Floating launcher button -->
		<button
			class="synaplan-chat-fab"
			:class="{ 'is-open': open }"
			:title="t('synaplan_integration', 'Chat with Synaplan AI')"
			:aria-label="t('synaplan_integration', 'Chat with Synaplan AI')"
			:aria-expanded="open"
			@click="open = !open">
			<svg
				v-if="!open"
				width="26"
				height="26"
				viewBox="0 0 24 24"
				fill="currentColor"
				aria-hidden="true">
				<path
					d="M12 3C6.48 3 2 6.86 2 11.5c0 2.3 1.1 4.38 2.9 5.9-.13 1.2-.6 2.5-1.5 3.6 1.7-.2 3.3-.8 4.6-1.8 1.2.4 2.6.6 4 .6 5.52 0 10-3.86 10-8.3S17.52 3 12 3z" />
			</svg>
			<svg
				v-else
				width="22"
				height="22"
				viewBox="0 0 24 24"
				fill="currentColor"
				aria-hidden="true">
				<path
					d="M18.3 5.71 12 12.01l-6.3-6.3-1.4 1.41 6.29 6.3-6.3 6.3 1.41 1.4 6.3-6.29 6.3 6.3 1.4-1.41-6.29-6.3 6.3-6.3z" />
			</svg>
		</button>
	</div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import ResearchChat from './ResearchChat.vue'

const open = ref(false)
</script>

<style scoped>
.synaplan-chat-fab {
	position: fixed;
	right: 24px;
	bottom: 24px;
	width: 56px;
	height: 56px;
	border-radius: 50%;
	border: none;
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.28);
	z-index: 10000;
	transition:
		transform 0.15s ease,
		background 0.15s ease;
}

.synaplan-chat-fab:hover {
	transform: scale(1.06);
	background: var(--color-primary-element-hover, #0074b3);
}

.synaplan-chat-launcher-panel {
	position: fixed;
	right: 24px;
	bottom: 92px;
	width: min(420px, calc(100vw - 32px));
	height: min(640px, calc(100vh - 140px));
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: 16px;
	box-shadow: 0 8px 40px rgba(0, 0, 0, 0.35);
	display: flex;
	flex-direction: column;
	overflow: hidden;
	z-index: 10000;
}

.launcher-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border, #e0e0e0);
	background: var(--color-main-background, #fff);
	flex-shrink: 0;
}

.launcher-title {
	font-weight: 700;
	color: var(--color-main-text, #222);
}

.launcher-close {
	width: 32px;
	height: 32px;
	border: none;
	background: transparent;
	color: var(--color-main-text, #222);
	font-size: 22px;
	line-height: 1;
	border-radius: 8px;
	cursor: pointer;
}

.launcher-close:hover {
	background: var(--color-background-hover, #ededed);
}

.launcher-body {
	flex: 1;
	min-height: 0;
	overflow: hidden;
}

/* Re-flow the embedded full-page ResearchChat to fill the compact panel. */
.launcher-body :deep(.synaplan-research-wrapper) {
	min-height: 0;
	height: 100%;
	padding: 0;
	align-items: stretch;
}

.launcher-body :deep(.synaplan-research) {
	max-width: none;
	width: 100%;
	height: 100%;
	max-height: none;
	border: none;
	border-radius: 0;
	box-shadow: none;
	padding: 16px;
	background: transparent;
}
</style>
