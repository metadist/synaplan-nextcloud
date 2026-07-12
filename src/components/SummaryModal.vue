<template>
	<NcDialog
		:open="opened"
		:name="dialogTitle"
		size="normal"
		@update:open="onClose">
		<div class="synaplan-summary-modal">
			<!-- Options (shown before result) -->
			<div v-if="!loading && !result" class="options">
				<div class="field">
					<label for="summary-type">{{
						t('synaplan_integration', 'Summary type')
					}}</label>
					<NcSelect
						v-model="summaryType"
						input-id="summary-type"
						:options="summaryTypeOptions"
						:clearable="false" />
				</div>

				<div class="field">
					<label for="summary-length">{{
						t('synaplan_integration', 'Length')
					}}</label>
					<NcSelect
						v-model="summaryLength"
						input-id="summary-length"
						:options="lengthOptions"
						:clearable="false" />
				</div>

				<div class="field">
					<label for="summary-lang">{{
						t('synaplan_integration', 'Output language')
					}}</label>
					<NcSelect
						v-model="outputLanguage"
						input-id="summary-lang"
						:options="languageOptions"
						:clearable="false" />
				</div>
			</div>

			<!-- Loading state -->
			<div v-if="loading" class="loading">
				<span class="syn-spinner" aria-hidden="true" />
				<p>{{ t('synaplan_integration', 'Generating summary...') }}</p>
			</div>

			<!-- Result -->
			<div v-if="result" class="result">
				<NcRichText
					class="result-md markdown-body"
					:text="result"
					:use-markdown="true"
					:use-extended-markdown="true" />
			</div>

			<!-- Error -->
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton v-if="result" type="secondary" @click="copyToClipboard">
				{{
					copied
						? t('synaplan_integration', 'Copied!')
						: t('synaplan_integration', 'Copy')
				}}
			</NcButton>
			<NcButton v-if="!loading && !result" type="primary" @click="doSummarize">
				{{ t('synaplan_integration', 'Summarize') }}
			</NcButton>
			<NcButton v-if="result" type="primary" @click="onClose">
				{{ t('synaplan_integration', 'Done') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios, { isAxiosError } from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRichText from '@nextcloud/vue/components/NcRichText'
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
const result = ref('')
const error = ref('')
const copied = ref(false)

const summaryType = ref({
	id: 'bullet-points',
	label: t('synaplan_integration', 'Bullet points'),
})
const summaryLength = ref({
	id: 'medium',
	label: t('synaplan_integration', 'Medium'),
})
const outputLanguage = ref({ id: 'en', label: 'English' })

const summaryTypeOptions = [
	{ id: 'bullet-points', label: t('synaplan_integration', 'Bullet points') },
	{ id: 'abstractive', label: t('synaplan_integration', 'Paragraph') },
	{ id: 'extractive', label: t('synaplan_integration', 'Key sentences') },
]

const lengthOptions = [
	{ id: 'short', label: t('synaplan_integration', 'Short') },
	{ id: 'medium', label: t('synaplan_integration', 'Medium') },
	{ id: 'long', label: t('synaplan_integration', 'Long') },
]

const languageOptions = [
	{ id: 'en', label: 'English' },
	{ id: 'de', label: 'Deutsch' },
	{ id: 'fr', label: 'Français' },
	{ id: 'es', label: 'Español' },
	{ id: 'it', label: 'Italiano' },
]

const dialogTitle = computed(() =>
	t('synaplan_integration', 'Summarize: {fileName}', { fileName: props.fileName }),
)

const baseUrl = generateUrl('/apps/synaplan_integration')

/**
 * Preselect the admin-configured / interface-resolved default language so the
 * output isn't silently English for non-English users.
 */
async function loadDefaultLanguage() {
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/client-config`)
		const match = languageOptions.find((o) => o.id === data.language)
		if (match) {
			outputLanguage.value = match
		}
	} catch {
		// Keep the English default if the config can't be loaded.
	}
}

onMounted(() => {
	loadDefaultLanguage()
})

/**
 * Send summarization request to backend.
 */
async function doSummarize() {
	loading.value = true
	error.value = ''
	result.value = ''

	try {
		const { data } = await axios.post(
			`${baseUrl}/api/v1/summarize/${props.fileId}`,
			{
				summaryType: summaryType.value.id,
				length: summaryLength.value.id,
				outputLanguage: outputLanguage.value.id,
			},
		)

		if (data.success) {
			result.value = data.summary
		} else {
			error.value =
				data.error || t('synaplan_integration', 'Summarization failed')
			showError(error.value)
		}
	} catch (err: unknown) {
		error.value = isAxiosError(err)
			? err.response?.data?.error || err.message
			: t('synaplan_integration', 'Unknown error')
		showError(error.value)
	} finally {
		loading.value = false
	}
}

/**
 * Copy summary result to clipboard.
 */
async function copyToClipboard() {
	try {
		await navigator.clipboard.writeText(result.value)
		copied.value = true
		setTimeout(() => {
			copied.value = false
		}, 2000)
	} catch {
		// Fallback: select text
	}
}

/**
 * Close the dialog.
 */
function onClose() {
	opened.value = false
	emit('close', result.value ? true : null)
}
</script>

<style scoped>
.synaplan-summary-modal {
	padding: 16px 0;
	min-height: 120px;
}

.options {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.field {
	display: flex;
	align-items: center;
	gap: 12px;
}

.field label {
	flex: 0 0 140px;
	font-weight: bold;
	text-align: right;
	white-space: nowrap;
}

.field :deep(.v-select) {
	flex: 1;
	min-width: 0;
}

.loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 24px;
}

/* Single-ring spinner (replaces NcLoadingIcon's twin-circle look). */
.syn-spinner {
	width: 40px;
	height: 40px;
	border: 3px solid var(--color-border-dark, rgba(127, 127, 127, 0.4));
	border-top-color: var(--color-primary-element, #0082c9);
	border-radius: 50%;
	animation: syn-spin 0.8s linear infinite;
}

@keyframes syn-spin {
	to {
		transform: rotate(360deg);
	}
}

.result-md {
	line-height: 1.6;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	padding: 16px;
	max-height: 400px;
	overflow-y: auto;
}

/* Rendered markdown — trim default block margins and keep lists tight. */
.markdown-body :deep(> *:first-child) {
	margin-top: 0;
}

.markdown-body :deep(> *:last-child) {
	margin-bottom: 0;
}

.markdown-body :deep(p) {
	margin: 0 0 8px;
}

.markdown-body :deep(ul),
.markdown-body :deep(ol) {
	margin: 4px 0 8px;
	padding-left: 18px;
}

.markdown-body :deep(li) {
	margin: 1px 0;
}

.markdown-body :deep(li > p) {
	margin: 0;
}

.markdown-body :deep(h1),
.markdown-body :deep(h2),
.markdown-body :deep(h3) {
	margin: 12px 0 6px;
	line-height: 1.3;
}

.markdown-body :deep(pre),
.markdown-body :deep(code) {
	background: var(--color-background-darker, rgba(0, 0, 0, 0.06));
	border-radius: 4px;
	font-family: var(--font-face-monospace, monospace);
}

.markdown-body :deep(pre) {
	padding: 10px 12px;
	overflow-x: auto;
}

.markdown-body :deep(code) {
	padding: 1px 5px;
}

.markdown-body :deep(pre code) {
	padding: 0;
	background: none;
}

.markdown-body :deep(a) {
	color: var(--color-primary-element, #0082c9);
}
</style>
