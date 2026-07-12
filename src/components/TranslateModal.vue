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
				<span class="syn-spinner" aria-hidden="true" />
				<p>{{ t('synaplan_integration', 'Translating...') }}</p>
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
 * Preselect the admin-configured / interface-resolved default language as the
 * translation target so it isn't silently English for non-English users.
 */
async function loadDefaultLanguage() {
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/client-config`)
		const match = languageOptions.find((o) => o.id === data.language)
		if (match) {
			targetLanguage.value = match
		}
	} catch {
		// Keep the English default if the config can't be loaded.
	}
}

onMounted(() => {
	loadDefaultLanguage()
})

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
