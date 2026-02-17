<template>
	<NcDialog
		:open="opened"
		:name="dialogTitle"
		size="normal"
		@update:open="onClose">
		<div class="synaplan-translate-modal">
			<!-- Language selection (shown before result) -->
			<div v-if="!loading && !result" class="options">
				<div class="field">
					<label for="target-lang">{{
						t('synaplan_integration', 'Target language')
					}}</label>
					<NcSelect
						v-model="targetLanguage"
						input-id="target-lang"
						:options="languageOptions"
						:clearable="false" />
				</div>
			</div>

			<!-- Loading state -->
			<div v-if="loading" class="loading">
				<NcLoadingIcon :size="44" />
				<p>{{ t('synaplan_integration', 'Translating...') }}</p>
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
			<NcButton v-if="!loading && !result" type="primary" @click="doTranslate">
				{{ t('synaplan_integration', 'Translate') }}
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

const targetLanguage = ref({ id: 'en', label: 'English' })

const languageOptions = [
	{ id: 'en', label: 'English' },
	{ id: 'de', label: 'Deutsch' },
	{ id: 'fr', label: 'Français' },
	{ id: 'es', label: 'Español' },
	{ id: 'it', label: 'Italiano' },
]

const dialogTitle = computed(() =>
	t('synaplan_integration', 'Translate: {fileName}', { fileName: props.fileName }),
)

const baseUrl = generateUrl('/apps/synaplan_integration')

/**
 * Send translation request to backend.
 */
async function doTranslate() {
	loading.value = true
	error.value = ''
	result.value = ''

	try {
		const { data } = await axios.post(
			`${baseUrl}/api/v1/translate/${props.fileId}`,
			{
				targetLanguage: targetLanguage.value.id,
			},
		)

		if (data.success) {
			result.value = data.translation
		} else {
			error.value =
				data.error || t('synaplan_integration', 'Translation failed')
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
 * Copy translation result to clipboard.
 */
async function copyToClipboard() {
	try {
		await navigator.clipboard.writeText(result.value)
		copied.value = true
		setTimeout(() => {
			copied.value = false
		}, 2000)
	} catch {
		// Fallback
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
.synaplan-translate-modal {
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
