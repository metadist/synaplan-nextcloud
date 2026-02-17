<template>
	<NcDialog
		:open="opened"
		:name="dialogTitle"
		size="normal"
		@update:open="onClose">
		<div class="synaplan-knowledge-modal">
			<!-- Status: Uploading -->
			<div v-if="loading" class="loading-state">
				<NcLoadingIcon :size="44" />
				<p class="loading-text">
					{{
						t('synaplan_integration', 'Uploading and processing file...')
					}}
				</p>
				<p class="loading-hint">
					{{
						t(
							'synaplan_integration',
							'Text extraction and vectorization may take a few minutes for large files.',
						)
					}}
				</p>
				<p v-if="elapsedSeconds > 0" class="loading-timer">
					{{ formattedElapsed }}
				</p>
			</div>

			<!-- Status: Success -->
			<div v-else-if="uploadSuccess" class="success-state">
				<div class="success-icon-wrapper">
					<svg
						class="success-checkmark"
						width="56"
						height="56"
						viewBox="0 0 52 52"
						xmlns="http://www.w3.org/2000/svg">
						<circle
							cx="26"
							cy="26"
							r="25"
							fill="none"
							stroke="#22cc55"
							stroke-width="2" />
						<path
							fill="none"
							stroke="#22cc55"
							stroke-width="3"
							stroke-linecap="round"
							stroke-linejoin="round"
							d="M14.1 27.2l7.1 7.2 16.7-16.8" />
					</svg>
				</div>
				<p class="success-text">
					{{
						t('synaplan_integration', 'File added to AI Knowledge Base')
					}}
				</p>
				<div class="success-details">
					<div class="detail-row">
						<span class="detail-label">{{
							t('synaplan_integration', 'Group')
						}}</span>
						<span class="detail-value">{{ selectedGroup }}</span>
					</div>
					<div class="detail-row">
						<span class="detail-label">{{
							t('synaplan_integration', 'Chunks created')
						}}</span>
						<span class="detail-value">{{ chunksCreated }}</span>
					</div>
					<div v-if="extractedLength > 0" class="detail-row">
						<span class="detail-label">{{
							t('synaplan_integration', 'Text extracted')
						}}</span>
						<span class="detail-value"
							>{{ extractedLength }}
							{{ t('synaplan_integration', 'characters') }}</span
						>
					</div>
				</div>
			</div>

			<!-- Status: Form -->
			<div v-else>
				<p class="description">
					{{
						t(
							'synaplan_integration',
							'Upload this file to the Synaplan knowledge base for AI-powered search and chat.',
						)
					}}
				</p>

				<!-- Group selection -->
				<div class="field">
					<label class="field-label">{{
						t('synaplan_integration', 'Knowledge group')
					}}</label>
					<NcSelect
						v-model="selectedGroup"
						:options="groupOptions"
						:placeholder="
							t(
								'synaplan_integration',
								'Select or type a new group...',
							)
						"
						:taggable="true"
						:loading="loadingGroups"
						:disabled="loading"
						@tag="onNewTag" />
				</div>

				<!-- Error -->
				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>

				<!-- File info -->
				<div class="file-info">
					<span class="file-icon">&#128196;</span>
					<span class="file-name">{{ fileName }}</span>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton v-if="uploadSuccess" type="primary" @click="onClose">
				{{ t('synaplan_integration', 'Done') }}
			</NcButton>
			<template v-else>
				<NcButton type="tertiary" :disabled="loading" @click="onClose">
					{{ t('synaplan_integration', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="loading || !selectedGroup"
					@click="uploadFile">
					{{ t('synaplan_integration', 'Add to Knowledge') }}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios, { isAxiosError } from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'

const props = defineProps<{
	fileId: number
	fileName: string
}>()

const emit = defineEmits<{
	close: [value: boolean | null]
}>()

const opened = ref(true)
const loading = ref(false)
const loadingGroups = ref(false)
const error = ref('')
const selectedGroup = ref('')
const groupOptions = ref<string[]>([])
const uploadSuccess = ref(false)
const chunksCreated = ref(0)
const extractedLength = ref(0)
const elapsedSeconds = ref(0)
let timerInterval: ReturnType<typeof setInterval> | null = null

const formattedElapsed = computed(() => {
	const mins = Math.floor(elapsedSeconds.value / 60)
	const secs = elapsedSeconds.value % 60
	if (mins > 0) {
		return `${mins}m ${secs}s`
	}
	return `${secs}s`
})

/**
 * Start the elapsed-time counter.
 */
function startTimer() {
	elapsedSeconds.value = 0
	timerInterval = setInterval(() => {
		elapsedSeconds.value++
	}, 1000)
}

/**
 * Stop the elapsed-time counter.
 */
function stopTimer() {
	if (timerInterval !== null) {
		clearInterval(timerInterval)
		timerInterval = null
	}
}

onUnmounted(() => stopTimer())

const baseUrl = generateUrl('/apps/synaplan_integration')

/**
 * Load available knowledge groups from the Synaplan API.
 */
async function loadGroups() {
	loadingGroups.value = true
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/knowledge/groups`)
		if (data.success && Array.isArray(data.groups)) {
			groupOptions.value = data.groups.map((g: { name: string }) => g.name)
		}
	} catch {
		// Groups are optional — user can type a new one
	} finally {
		loadingGroups.value = false
	}
}

/**
 * Handle new tag creation in NcSelect.
 *
 * @param {string} tag The new tag string entered by the user
 */
function onNewTag(tag: string) {
	const trimmed = tag.trim().toUpperCase()
	if (trimmed && !groupOptions.value.includes(trimmed)) {
		groupOptions.value.push(trimmed)
	}
	selectedGroup.value = trimmed
}

/**
 * Upload the file to the Synaplan knowledge base for vectorization.
 */
async function uploadFile() {
	if (!selectedGroup.value || loading.value) return

	loading.value = true
	error.value = ''
	startTimer()

	try {
		const { data } = await axios.post(
			`${baseUrl}/api/v1/knowledge/upload/${props.fileId}`,
			{ groupKey: selectedGroup.value },
		)

		if (data.success) {
			uploadSuccess.value = true
			chunksCreated.value = data.chunksCreated || 0
			extractedLength.value = data.extractedTextLength || 0
		} else {
			error.value = data.error || t('synaplan_integration', 'Upload failed')
		}
	} catch (err: unknown) {
		error.value = isAxiosError(err)
			? err.response?.data?.error || err.message
			: t('synaplan_integration', 'Unknown error')
	} finally {
		stopTimer()
		loading.value = false
	}
}

/**
 * Close the modal dialog.
 */
function onClose() {
	opened.value = false
	emit('close', uploadSuccess.value ? true : null)
}

const dialogTitle = t('synaplan_integration', 'Add to AI Knowledge')

onMounted(() => {
	loadGroups()
})
</script>

<style scoped>
.synaplan-knowledge-modal {
	padding: 8px 0;
}

.description {
	color: var(--color-text-maxcontrast, #767676);
	margin: 0 0 16px;
	line-height: 1.5;
}

.field {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.field-label {
	flex: 0 0 140px;
	font-weight: 600;
	color: var(--color-main-text, #222);
}

.field :deep(.v-select) {
	flex: 1;
	min-width: 0;
}

.file-info {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 14px;
	background: var(--color-background-dark, #f5f5f5);
	border-radius: 8px;
	margin-top: 8px;
}

.file-icon {
	font-size: 1.2em;
}

.file-name {
	font-weight: 500;
	word-break: break-all;
}

/* Loading state */
.loading-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 16px;
	text-align: center;
}

.loading-text {
	font-size: 1.1em;
	font-weight: 600;
	margin: 8px 0 0;
	color: var(--color-main-text, #222);
}

.loading-hint {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 0.9em;
	margin: 0;
	line-height: 1.4;
}

.loading-timer {
	font-size: 0.85em;
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast, #767676);
	background: var(--color-background-dark, #f5f5f5);
	border-radius: 12px;
	padding: 4px 14px;
	margin: 4px 0 0;
}

/* Success state */
.success-state {
	text-align: center;
	padding: 24px 0;
}

.success-icon-wrapper {
	display: flex;
	justify-content: center;
	margin-bottom: 16px;
}

.success-checkmark {
	width: 56px;
	height: 56px;
	min-width: 56px;
	min-height: 56px;
	max-width: 56px;
	max-height: 56px;
	color: #22cc55;
	animation: scale-in 0.3s ease-out;
}

@keyframes scale-in {
	0% {
		transform: scale(0.5);
		opacity: 0;
	}
	100% {
		transform: scale(1);
		opacity: 1;
	}
}

.success-text {
	font-size: 1.15em;
	font-weight: 600;
	margin: 0 0 20px;
	color: var(--color-main-text, #fff);
}

.success-details {
	text-align: left;
	max-width: 320px;
	margin: 0 auto;
	background: var(--color-background-dark, rgba(255, 255, 255, 0.08));
	border-radius: 10px;
	padding: 12px 16px;
}

.detail-row {
	display: flex;
	justify-content: space-between;
	padding: 8px 0;
}

.detail-row + .detail-row {
	border-top: 1px solid var(--color-border, rgba(255, 255, 255, 0.15));
}

.detail-label {
	color: var(--color-text-maxcontrast, #aaa);
}

.detail-value {
	font-weight: 600;
	color: var(--color-main-text, #fff);
}
</style>
