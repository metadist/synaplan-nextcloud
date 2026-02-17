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
				<NcLoadingIcon :size="44" />
				<p>{{ t('synaplan_integration', 'Generating summary...') }}</p>
			</div>

			<!-- Result -->
			<div v-if="result" class="result">
				<div class="result-content" v-text="result" />
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
import { ref, computed } from 'vue'
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
		}
	} catch (err: unknown) {
		error.value = isAxiosError(err)
			? err.response?.data?.error || err.message
			: 'Unknown error'
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

.result-content {
	white-space: pre-wrap;
	line-height: 1.6;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	padding: 16px;
	max-height: 400px;
	overflow-y: auto;
}
</style>
