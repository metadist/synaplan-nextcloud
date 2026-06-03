<template>
	<NcDialog
		:open="opened"
		:name="dialogTitle"
		size="normal"
		@update:open="onClose">
		<div class="synaplan-knowledge-modal">
			<!-- Status: Uploading -->
			<div v-if="loading" class="loading-state">
				<div
					class="progress-track"
					role="progressbar"
					:aria-valuenow="progressValue"
					aria-valuemin="0"
					aria-valuemax="100">
					<div
						class="progress-fill"
						:style="{ width: progressValue + '%' }" />
				</div>

				<p class="loading-text">
					{{ stages[currentStageIndex] }}
				</p>

				<ul class="stage-list">
					<li
						v-for="(stage, i) in stages"
						:key="i"
						:class="[
							'stage-item',
							{
								done: i < currentStageIndex,
								active: i === currentStageIndex,
							},
						]">
						<span class="stage-marker">
							<NcLoadingIcon
								v-if="i === currentStageIndex"
								:size="16" />
							<span
								v-else-if="i < currentStageIndex"
								class="stage-check"
								>✓</span
							>
							<span v-else class="stage-dot" />
						</span>
						<span class="stage-name">{{ stage }}</span>
					</li>
				</ul>

				<p class="loading-hint">
					{{
						t(
							'synaplan_integration',
							'Large documents take a little longer to vectorize.',
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

// Ordered processing phases shown while the (single, blocking) upload request
// runs server-side. We can't read true per-chunk progress, so the stage is
// advanced on an elapsed-time heuristic and dwells on the slow embedding step.
const stages = computed(() => [
	t('synaplan_integration', 'Uploading file'),
	t('synaplan_integration', 'Extracting text'),
	t('synaplan_integration', 'Creating chunks'),
	t('synaplan_integration', 'Generating embeddings'),
])

const currentStageIndex = computed(() => {
	const s = elapsedSeconds.value
	if (s < 2) return 0
	if (s < 4) return 1
	if (s < 6) return 2
	return 3
})

// Asymptotic fill that approaches but never reaches 100% until the request
// actually returns (then the dialog switches to the success state). Avoids
// claiming a precise percentage we don't have.
const progressValue = computed(() =>
	Math.min(95, Math.round((1 - Math.exp(-elapsedSeconds.value / 12)) * 100)),
)

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

		const created = data.chunksCreated || 0
		if (data.success && created > 0) {
			uploadSuccess.value = true
			chunksCreated.value = created
			extractedLength.value = data.extractedTextLength || 0
		} else if (data.success) {
			// The server accepted the file but stored zero chunks. Surface this
			// as a failure instead of a misleading green success screen.
			error.value = t(
				'synaplan_integration',
				'No text could be indexed (0 chunks created). The file may be empty or image-only, or the embedding service may be unavailable. Please check the document and try again.',
			)
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
	padding: 28px 16px;
	text-align: center;
}

/* Progress bar */
.progress-track {
	width: 100%;
	max-width: 360px;
	height: 8px;
	border-radius: 999px;
	background: var(--color-background-dark, #ededed);
	overflow: hidden;
	margin-bottom: 4px;
}

.progress-fill {
	height: 100%;
	border-radius: 999px;
	background: var(--color-primary-element, #0082c9);
	transition: width 0.6s ease;
}

.loading-text {
	font-size: 1.1em;
	font-weight: 600;
	margin: 8px 0 0;
	color: var(--color-main-text, #222);
}

/* Stage checklist */
.stage-list {
	list-style: none;
	margin: 4px 0 0;
	padding: 0;
	text-align: left;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.stage-item {
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 0.92em;
	color: var(--color-text-maxcontrast, #767676);
	transition: color 0.2s ease;
}

.stage-item.active {
	color: var(--color-main-text, #222);
	font-weight: 600;
}

.stage-item.done {
	color: var(--color-main-text, #222);
}

.stage-marker {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	flex-shrink: 0;
}

.stage-check {
	color: var(--color-success, #2fa84f);
	font-weight: 700;
	line-height: 1;
}

.stage-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	border: 1.5px solid var(--color-border-dark, #c8c8c8);
}

.loading-hint {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 0.9em;
	margin: 4px 0 0;
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
