<template>
	<NcDialog
		:open="opened"
		:name="t('synaplan_integration', 'Files in your Synaplan')"
		size="normal"
		@update:open="onClose">
		<div class="synaplan-from-modal">
			<p v-if="loading" class="hint">
				{{ t('synaplan_integration', 'Loading...') }}
			</p>
			<p v-else-if="files.length === 0 && !failure" class="hint">
				{{ t('synaplan_integration', 'Nothing to copy yet.') }}
			</p>
			<ul v-else class="files">
				<li v-for="file in files" :key="file.id" class="row">
					<span class="name">{{ file.name }}</span>
					<NcButton
						variant="secondary"
						:disabled="copyingId !== null"
						@click="copy(file)">
						{{
							copyingId === file.id
								? t('synaplan_integration', 'Copying…')
								: t('synaplan_integration', 'Copy')
						}}
					</NcButton>
				</li>
			</ul>
			<p v-if="copied" class="hint">
				{{ copied }}
			</p>
			<FailureNote :failure="failure" />
		</div>
		<template #actions>
			<NcButton variant="primary" @click="onClose">
				{{ t('synaplan_integration', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import FailureNote from './FailureNote.vue'
import { failureFromError, type Failure } from '../failures'

const props = defineProps<{
	folder: string
}>()

const emit = defineEmits<{
	close: [value: boolean | null]
}>()

interface ListedFile {
	id: number
	name: string
	generated: boolean
}

const opened = ref(true)
const loading = ref(true)
const copyingId = ref<number | null>(null)
const files = ref<ListedFile[]>([])
const failure = ref<Failure | null>(null)
const copied = ref('')
const baseUrl = generateUrl('/apps/synaplan_integration')

/**
 * Close the dialog.
 */
function onClose() {
	opened.value = false
	emit('close', copied.value !== '' ? true : null)
}

/**
 * Recent files, generated ones first.
 */
async function load() {
	loading.value = true
	failure.value = null
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/from-synaplan`)
		files.value = Array.isArray(data.files) ? data.files : []
	} catch (error: unknown) {
		failure.value = failureFromError(error)
	} finally {
		loading.value = false
	}
}

/**
 * Copy one file into the folder that is open.
 *
 * @param {ListedFile} file The row the person chose
 */
async function copy(file: ListedFile) {
	if (copyingId.value !== null) {
		return
	}
	copyingId.value = file.id
	failure.value = null
	copied.value = ''
	try {
		const { data } = await axios.post(`${baseUrl}/api/v1/from-synaplan`, {
			fileId: file.id,
			name: file.name,
			folder: props.folder,
		})
		if (data.success) {
			copied.value = t(
				'synaplan_integration',
				'Synaplan copied {name} to {folder}.',
				{ name: data.name, folder: data.folder },
			)
		} else {
			copied.value = ''
			failure.value = {
				text: t('synaplan_integration', 'Could not copy this file.'),
			}
		}
	} catch (error: unknown) {
		failure.value = failureFromError(error)
	} finally {
		copyingId.value = null
	}
}

onMounted(load)
</script>

<style scoped>
.synaplan-from-modal {
	padding: 8px 0 16px;
}

.hint {
	margin: 0 0 12px;
	color: var(--color-main-text, #222);
}

.files {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-height: 320px;
	overflow: auto;
}

.row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.name {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	color: var(--color-main-text, #222);
}
</style>
