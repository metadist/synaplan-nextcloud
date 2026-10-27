<template>
	<div class="synaplan-sidebar-tab" data-testid="synaplan-sidebar-tab">
		<div v-if="loading" class="sp-sidebar-loading">
			<NcLoadingIcon :size="24" />
		</div>

		<div
			v-else-if="unreachable"
			class="sp-sidebar-banner"
			data-testid="synaplan-sidebar-unreachable">
			{{ t('synaplan_integration', "Couldn't check Synaplan right now") }}
		</div>

		<div v-else-if="!enabled" class="sp-sidebar-banner">
			{{ t('synaplan_integration', 'Connect your Synaplan account first.') }}
		</div>

		<div v-else class="sp-sidebar-body">
			<div class="sp-card">
				<div class="sp-card-header" :class="stateClass">
					<span
						class="sp-sidebar-glyph"
						:class="stateClass"
						v-html="iconHtml" />
					<div class="sp-sidebar-titles">
						<div
							class="sp-sidebar-state"
							data-testid="synaplan-sidebar-state">
							{{ stateHeading }}
						</div>
						<div v-if="knowledgeFolder" class="sp-sidebar-sub">
							{{
								t(
									'synaplan_integration',
									'In your knowledge folder “{folder}”',
									{ folder: knowledgeFolder },
								)
							}}
						</div>
					</div>
				</div>

				<div v-if="isSearchableOrPending" class="sp-card-sections">
					<div class="sp-section">
						<div class="sp-section-title">
							{{ t('synaplan_integration', 'Who owns this') }}
						</div>
						<div class="sp-section-text">
							{{ t('synaplan_integration', 'Added by you.') }}
						</div>
					</div>

					<div class="sp-section">
						<div class="sp-section-title">
							{{ t('synaplan_integration', 'Who else can use it') }}
						</div>
						<div class="sp-section-text">
							{{
								t(
									'synaplan_integration',
									'Only you can search this file in your knowledge folder.',
								)
							}}
						</div>
					</div>

					<div class="sp-section">
						<div class="sp-section-title">
							{{ t('synaplan_integration', 'What it touches') }}
						</div>
						<div class="sp-section-text">
							{{
								keptUpToDate
									? t(
											'synaplan_integration',
											'Answers questions from “{folder}” and stays up to date when the file changes here.',
											{ folder: knowledgeFolder || 'DEFAULT' },
										)
									: t(
											'synaplan_integration',
											'Answers questions from “{folder}”.',
											{ folder: knowledgeFolder || 'DEFAULT' },
										)
							}}
						</div>
					</div>

					<div class="sp-section">
						<div class="sp-section-title">
							{{ t('synaplan_integration', 'Where it came from') }}
						</div>
						<div class="sp-section-text">
							{{
								t(
									'synaplan_integration',
									'From this Nextcloud ({name}).',
									{ name: fileName },
								)
							}}
						</div>
					</div>
				</div>

				<div v-else class="sp-card-empty">
					{{
						t(
							'synaplan_integration',
							'The AI can then find this file when you ask a question, here in the chat or in Synaplan.',
						)
					}}
				</div>
			</div>

			<div v-if="watches.length > 0" class="sp-sidebar-watches">
				<div v-for="item in watches" :key="item.id" class="sp-watch-box">
					<div class="sp-watch-sentence">{{ item.sentence }}</div>
					<div class="sp-watch-hint">
						{{
							t(
								'synaplan_integration',
								'Everyone who can open this folder sees that it is watched.',
							)
						}}
					</div>
				</div>
			</div>

			<div v-if="kind === 'file'" class="sp-sidebar-actions">
				<NcButton
					v-if="isSearchableOrPending"
					variant="primary"
					:disabled="removing"
					class="sp-btn-full"
					@click="openChat">
					{{ t('synaplan_integration', 'Chat about this file') }}
				</NcButton>
				<NcButton
					:variant="isSearchableOrPending ? 'secondary' : 'primary'"
					:disabled="removing"
					class="sp-btn-full"
					@click="openKnowledgeModal">
					{{
						isSearchableOrPending
							? t('synaplan_integration', 'Update')
							: t('synaplan_integration', 'Make searchable by AI')
					}}
				</NcButton>
				<NcButton
					v-if="isSearchableOrPending"
					variant="secondary"
					:disabled="removing"
					class="sp-btn-full sp-btn-remove"
					data-testid="synaplan-sidebar-remove"
					@click="removeFromSynaplan">
					{{ t('synaplan_integration', 'Remove from Synaplan') }}
				</NcButton>
				<a
					v-if="openSynaplanUrl"
					:href="openSynaplanUrl"
					target="_blank"
					rel="noopener noreferrer"
					class="sp-sidebar-link"
					data-testid="synaplan-sidebar-open">
					<span>{{
						t('synaplan_integration', 'Open in {app}', {
							app: 'Synaplan',
						})
					}}</span>
					<span aria-hidden="true">↗</span>
				</a>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, createApp, ref, watch, type Ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import {
	badgeForState,
	glyphMarkup,
	type CloudFileStateCode,
	type GlyphOverrides,
} from '../cloud_state/glyphs'
import {
	invalidateCloudStatusCache,
	onCloudStatusInvalidated,
	type CloudWatchSummary,
} from '../cloud_state/statusBatcher'
import ChatModal from './ChatModal.vue'
import KnowledgeModal from './KnowledgeModal.vue'

const props = defineProps<{
	fileIdRef: Ref<number>
	fileNameRef: Ref<string>
}>()

const baseUrl = generateUrl('/apps/synaplan_integration')
const loading = ref(false)
const removing = ref(false)
const enabled = ref(true)
const unreachable = ref(false)
const kind = ref<'file' | 'folder'>('file')
const state = ref<CloudFileStateCode>('none')
const knowledgeFolder = ref<string | null>(null)
const keptUpToDate = ref(false)
const watches = ref<CloudWatchSummary[]>([])
const glyphs = ref<GlyphOverrides>({})
const openSynaplanUrl = ref('')

const fileName = computed(() => props.fileNameRef.value)

const isSearchableOrPending = computed(() => state.value !== 'none')

const stateHeading = computed(() => {
	switch (state.value) {
		case 'searchable':
			return t('synaplan_integration', 'Searchable by AI')
		case 'adding':
			return t('synaplan_integration', 'Being made searchable…')
		case 'updating':
			return t('synaplan_integration', 'Changed — Synaplan is updating it')
		case 'changed':
			return t(
				'synaplan_integration',
				'Changed since it was made searchable — open to update',
			)
		case 'failed':
			return t(
				'synaplan_integration',
				'Could not be made searchable — open to see why',
			)
		default:
			return t('synaplan_integration', 'Not searchable by AI')
	}
})

const stateClass = computed(() => {
	if (state.value === 'failed') return 'is-error'
	if (state.value === 'changed' || state.value === 'updating') return 'is-warn'
	if (state.value === 'adding') return 'is-info'
	if (state.value === 'searchable') return 'is-ok'
	return 'is-muted'
})

const iconHtml = computed(() =>
	glyphMarkup({
		eyes: watches.value.length > 0,
		badge: badgeForState(state.value, watches.value[0]?.state ?? null),
		size: 20,
		overrides: glyphs.value,
	}),
)

/**
 * Load the sidebar detail for the active file or folder.
 */
async function loadDetail(): Promise<void> {
	const id = props.fileIdRef.value
	if (!id || id <= 0) {
		return
	}
	loading.value = true
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/cloud/files/${id}`)
		enabled.value = Boolean(data?.enabled)
		unreachable.value = Boolean(data?.unreachable)
		kind.value = data?.kind === 'folder' ? 'folder' : 'file'
		state.value = (data?.state as CloudFileStateCode) || 'none'
		knowledgeFolder.value = data?.knowledgeFolder || null
		keptUpToDate.value = Boolean(data?.keptUpToDate)
		watches.value = Array.isArray(data?.watches) ? data.watches : []
		glyphs.value = data?.glyphs || {}
		openSynaplanUrl.value = data?.openSynaplanUrl || ''
	} catch {
		unreachable.value = true
	} finally {
		loading.value = false
	}
}

/**
 * Remove this file from Synaplan's AI search.
 */
async function removeFromSynaplan(): Promise<void> {
	const id = props.fileIdRef.value
	if (!id || removing.value) {
		return
	}
	removing.value = true
	try {
		const { data } = await axios.delete(`${baseUrl}/api/v1/knowledge/${id}`)
		if (data?.success) {
			state.value = 'none'
			knowledgeFolder.value = null
			keptUpToDate.value = false
			invalidateCloudStatusCache()
			showSuccess(
				t('synaplan_integration', 'This file is no longer searchable by AI'),
			)
		}
	} catch {
		showError(t('synaplan_integration', "Couldn't check Synaplan right now"))
	} finally {
		removing.value = false
	}
}

/**
 * Open the Make searchable by AI modal for this file.
 */
function openKnowledgeModal(): void {
	const id = props.fileIdRef.value
	if (!id) return
	const container = document.createElement('div')
	document.body.appendChild(container)
	const app = createApp(KnowledgeModal, {
		fileId: id,
		fileName: fileName.value,
		onClose: () => {
			app.unmount()
			container.remove()
			invalidateCloudStatusCache()
			loadDetail()
		},
	})
	app.mount(container)
}

/**
 * Open the per-file chat modal.
 */
function openChat(): void {
	const id = props.fileIdRef.value
	if (!id) return
	const container = document.createElement('div')
	document.body.appendChild(container)
	const app = createApp(ChatModal, {
		fileId: id,
		fileName: fileName.value,
		onClose: () => {
			app.unmount()
			container.remove()
		},
	})
	app.mount(container)
}

watch(
	() => props.fileIdRef.value,
	() => {
		loadDetail()
	},
	{ immediate: true },
)

onCloudStatusInvalidated(() => {
	loadDetail()
})
</script>

<style scoped>
.synaplan-sidebar-tab {
	display: block;
	padding: 6px 4px 18px;
	box-sizing: border-box;
	text-align: left;
}

.sp-sidebar-loading {
	display: flex;
	justify-content: center;
	padding: 28px 0;
}

.sp-sidebar-banner {
	padding: 12px 14px;
	border-radius: 12px;
	background: var(--color-background-hover, rgba(127, 127, 127, 0.08));
	border: 1px solid var(--color-border, rgba(127, 127, 127, 0.2));
	color: var(--color-text-maxcontrast, #767676);
	font-size: 13.5px;
	line-height: 1.45;
}

.sp-sidebar-body {
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.sp-card {
	border-radius: 12px;
	border: 1px solid var(--color-border, rgba(127, 127, 127, 0.22));
	background: var(--color-background-hover, rgba(127, 127, 127, 0.06));
	overflow: hidden;
}

.sp-card-header {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 14px 16px;
	border-bottom: 1px solid var(--color-border, rgba(127, 127, 127, 0.16));
}

.sp-card-header.is-ok {
	background: light-dark(rgba(5, 150, 105, 0.07), rgba(52, 211, 153, 0.08));
}

.sp-sidebar-glyph {
	position: relative;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	border-radius: 10px;
	background: light-dark(rgba(5, 150, 105, 0.14), rgba(52, 211, 153, 0.16));
	flex-shrink: 0;
}

.sp-sidebar-glyph.is-ok {
	color: light-dark(#059669, #34d399);
}

.sp-sidebar-glyph.is-warn {
	color: light-dark(#b45309, #fbbf24);
	background: light-dark(rgba(180, 83, 9, 0.12), rgba(251, 191, 36, 0.15));
}

.sp-sidebar-glyph.is-error {
	color: light-dark(#dc2626, #f87171);
	background: light-dark(rgba(220, 38, 38, 0.12), rgba(248, 113, 113, 0.15));
}

.sp-sidebar-glyph.is-info {
	color: light-dark(#0284c7, #38bdf8);
	background: light-dark(rgba(2, 132, 199, 0.12), rgba(56, 189, 248, 0.15));
}

.sp-sidebar-glyph.is-muted {
	color: var(--color-text-maxcontrast, #767676);
	background: var(--color-background-dark, rgba(127, 127, 127, 0.14));
}

.sp-sidebar-titles {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.sp-sidebar-state {
	font-size: 15.5px;
	font-weight: 700;
	line-height: 1.3;
	color: var(--color-main-text, #222);
}

.sp-sidebar-sub {
	font-size: 13px;
	line-height: 1.35;
	color: var(--color-text-maxcontrast, #767676);
}

.sp-card-sections {
	display: flex;
	flex-direction: column;
}

.sp-section {
	display: flex;
	flex-direction: column;
	gap: 3px;
	padding: 12px 16px;
	text-align: left;
}

.sp-section + .sp-section {
	border-top: 1px solid var(--color-border, rgba(127, 127, 127, 0.14));
}

.sp-section-title {
	font-size: 14.5px;
	font-weight: 700;
	line-height: 1.35;
	color: var(--color-main-text, #222);
	text-align: left;
}

.sp-section-text {
	font-size: 13.5px;
	line-height: 1.45;
	color: var(--color-text-maxcontrast, #767676);
	text-align: left;
	word-break: break-word;
}

.sp-card-empty {
	padding: 14px 16px;
	font-size: 13.5px;
	line-height: 1.5;
	color: var(--color-text-maxcontrast, #767676);
}

.sp-sidebar-watches {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sp-watch-box {
	padding: 12px 16px;
	border-radius: 12px;
	background: var(--color-background-hover, rgba(127, 127, 127, 0.08));
	border: 1px solid var(--color-border, rgba(127, 127, 127, 0.2));
}

.sp-watch-sentence {
	margin: 0 0 4px;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text, #222);
}

.sp-watch-hint {
	margin: 0;
	font-size: 12.5px;
	color: var(--color-text-maxcontrast, #767676);
}

.sp-sidebar-actions {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sp-btn-full {
	width: 100%;
	justify-content: center;
}

.sp-btn-remove {
	color: light-dark(#dc2626, #f87171) !important;
}

.sp-sidebar-link {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: 8px 12px;
	margin-top: 2px;
	font-size: 13.5px;
	font-weight: 600;
	color: var(--color-primary-element, #0082c9);
	text-decoration: none;
	border-radius: 8px;
}

.sp-sidebar-link:hover {
	text-decoration: underline;
	background: var(--color-background-hover, rgba(127, 127, 127, 0.08));
}
</style>
