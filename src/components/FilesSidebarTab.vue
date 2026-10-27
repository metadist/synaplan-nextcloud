<template>
	<div class="synaplan-sidebar-tab" data-testid="synaplan-sidebar-tab">
		<div v-if="loading" class="sp-sidebar-loading">
			<NcLoadingIcon :size="24" />
		</div>

		<p
			v-else-if="unreachable"
			class="sp-sidebar-quiet"
			data-testid="synaplan-sidebar-unreachable">
			{{ t('synaplan_integration', "Couldn't check Synaplan right now") }}
		</p>

		<p v-else-if="!enabled" class="sp-sidebar-quiet">
			{{ t('synaplan_integration', 'Connect your Synaplan account first.') }}
		</p>

		<div v-else class="sp-sidebar-body">
			<div class="sp-sidebar-head">
				<span
					class="sp-sidebar-glyph"
					:class="stateClass"
					v-html="iconHtml" />
				<div class="sp-sidebar-titles">
					<strong
						class="sp-sidebar-state"
						data-testid="synaplan-sidebar-state">
						{{ stateHeading }}
					</strong>
					<span v-if="knowledgeFolder" class="sp-sidebar-sub">
						{{
							t(
								'synaplan_integration',
								'In your knowledge folder “{folder}”',
								{ folder: knowledgeFolder },
							)
						}}
					</span>
				</div>
			</div>

			<dl v-if="isSearchableOrPending" class="sp-sidebar-facts">
				<div class="sp-fact">
					<dt>{{ t('synaplan_integration', 'Who owns this') }}</dt>
					<dd>{{ t('synaplan_integration', 'Added by you.') }}</dd>
				</div>
				<div class="sp-fact">
					<dt>{{ t('synaplan_integration', 'Who else can use it') }}</dt>
					<dd>
						{{
							t(
								'synaplan_integration',
								'Only you can search this file in your knowledge folder.',
							)
						}}
					</dd>
				</div>
				<div class="sp-fact">
					<dt>{{ t('synaplan_integration', 'What it touches') }}</dt>
					<dd>
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
					</dd>
				</div>
				<div class="sp-fact">
					<dt>{{ t('synaplan_integration', 'Where it came from') }}</dt>
					<dd>
						{{
							t(
								'synaplan_integration',
								'From this Nextcloud ({name}).',
								{
									name: fileName,
								},
							)
						}}
					</dd>
				</div>
			</dl>

			<p v-else class="sp-sidebar-hint">
				{{
					t(
						'synaplan_integration',
						'The AI can then find this file when you ask a question, here in the chat or in Synaplan.',
					)
				}}
			</p>

			<div v-if="watches.length > 0" class="sp-sidebar-watches">
				<div v-for="watch in watches" :key="watch.id" class="sp-watch-box">
					<p class="sp-watch-sentence">
						{{ watch.sentence }}
					</p>
					<p class="sp-watch-hint">
						{{
							t(
								'synaplan_integration',
								'Everyone who can open this folder sees that it is watched.',
							)
						}}
					</p>
				</div>
			</div>

			<div v-if="kind === 'file'" class="sp-sidebar-actions">
				<NcButton
					v-if="isSearchableOrPending"
					variant="secondary"
					:disabled="removing"
					@click="openChat">
					{{ t('synaplan_integration', 'Chat about this file') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="removing"
					@click="openKnowledgeModal">
					{{
						isSearchableOrPending
							? t('synaplan_integration', 'Update')
							: t('synaplan_integration', 'Make searchable by AI')
					}}
				</NcButton>
				<NcButton
					v-if="isSearchableOrPending"
					variant="error"
					:disabled="removing"
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
					{{
						t('synaplan_integration', 'Open in {app}', {
							app: 'Synaplan',
						})
					}}
					↗
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
 *
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
 *
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
 *
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
 *
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
	padding: 16px;
}

.sp-sidebar-loading {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}

.sp-sidebar-quiet {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 0.9em;
	margin: 0;
}

.sp-sidebar-head {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	margin-bottom: 16px;
}

.sp-sidebar-glyph {
	position: relative;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	flex-shrink: 0;
}

.sp-sidebar-glyph.is-ok {
	color: light-dark(#059669, #34d399);
}

.sp-sidebar-glyph.is-warn {
	color: light-dark(#b45309, #fbbf24);
}

.sp-sidebar-glyph.is-error {
	color: light-dark(#dc2626, #f87171);
}

.sp-sidebar-glyph.is-info {
	color: light-dark(#0284c7, #38bdf8);
}

.sp-sidebar-glyph.is-muted {
	color: var(--color-text-maxcontrast, #767676);
}

.sp-sidebar-titles {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.sp-sidebar-state {
	font-size: 1em;
	color: var(--color-main-text, #222);
}

.sp-sidebar-sub {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #767676);
}

.sp-sidebar-facts {
	margin: 0 0 16px;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.sp-fact dt {
	font-size: 0.8em;
	font-weight: 600;
	color: var(--color-text-maxcontrast, #767676);
	margin: 0 0 2px;
}

.sp-fact dd {
	margin: 0;
	font-size: 0.9em;
	color: var(--color-main-text, #222);
}

.sp-sidebar-hint {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast, #767676);
	margin: 0 0 16px;
	line-height: 1.45;
}

.sp-sidebar-watches {
	margin-bottom: 16px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sp-watch-box {
	padding: 10px 12px;
	border-radius: 8px;
	background: var(--color-background-dark, rgba(0, 0, 0, 0.05));
}

.sp-watch-sentence {
	margin: 0 0 4px;
	font-size: 0.9em;
	font-weight: 600;
}

.sp-watch-hint {
	margin: 0;
	font-size: 0.82em;
	color: var(--color-text-maxcontrast, #767676);
}

.sp-sidebar-actions {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sp-sidebar-link {
	display: inline-block;
	margin-top: 4px;
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-primary-element, #0082c9);
	text-decoration: none;
}

.sp-sidebar-link:hover {
	text-decoration: underline;
}
</style>
