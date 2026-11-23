<template>
	<NcDialog
		:open="opened"
		:name="t('synaplan_integration', 'Add from Synaplan')"
		size="normal"
		@update:open="onClose">
		<div class="synaplan-from-modal">
			<div class="filters">
				<NcSelect
					v-model="show"
					class="show"
					:options="showOptions"
					:clearable="false"
					:searchable="false"
					:reduce="(option: ShowOption) => option.id"
					:aria-label-combobox="t('synaplan_integration', 'Show')" />
				<NcTextField
					v-model="search"
					class="search"
					:label="t('synaplan_integration', 'Search…')"
					type="search" />
			</div>
			<p v-if="loading && files.length === 0" class="hint">
				{{ t('synaplan_integration', 'Loading…') }}
			</p>
			<p
				v-else-if="files.length === 0 && !failure && search.trim() !== ''"
				class="hint">
				{{ t('synaplan_integration', 'No file matches your search.') }}
			</p>
			<div v-else-if="files.length === 0 && !failure" class="empty">
				<p class="hint">
					{{
						t(
							'synaplan_integration',
							'Files Synaplan makes for you appear here.',
						)
					}}
				</p>
				<NcButton
					v-if="openSynaplanUrl"
					:href="openSynaplanUrl"
					target="_blank"
					rel="noopener noreferrer"
					variant="secondary">
					{{ t('synaplan_integration', 'Open Synaplan') }} ↗
				</NcButton>
			</div>
			<ul v-else class="files">
				<li v-for="file in files" :key="file.id" class="row">
					<NcCheckboxRadioSwitch
						:modelValue="file.id in picked"
						:disabled="adding"
						@update:modelValue="toggle(file, $event)">
						<span class="name">{{ file.name }}</span>
						<span class="meta">
							<template v-if="file.generated">
								{{ t('synaplan_integration', 'Generated') }} ·
							</template>
							<NcDateTime
								v-if="file.created > 0"
								:timestamp="file.created * 1000"
								relativeTime="long" />
						</span>
					</NcCheckboxRadioSwitch>
				</li>
			</ul>
			<NcButton
				v-if="page < pages"
				class="more"
				variant="secondary"
				:disabled="loading"
				@click="load(true)">
				{{ t('synaplan_integration', 'Show more') }}
			</NcButton>
			<p class="saved-into">
				{{
					t('synaplan_integration', 'Saved into: {folder}', {
						folder: folderLabel(props.folder),
					})
				}}
			</p>
			<FailureNote :failure="failure" />
		</div>
		<template #actions>
			<NcButton variant="secondary" :disabled="adding" @click="onClose">
				{{ t('synaplan_integration', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="pickedCount === 0 || adding"
				@click="add">
				{{ addLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import type { Failure } from '../failures'
import type { PickedFile } from '../fromSynaplan'

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit as emitEvent } from '@nextcloud/event-bus'
import {
	getClient,
	getDefaultPropfind,
	getRootPath,
	resultToNode,
} from '@nextcloud/files/dav'
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FailureNote from './FailureNote.vue'
import { failureFromError } from '../failures'
import { addFiles, folderLabel } from '../fromSynaplan'

const props = defineProps<{
	folder: string
}>()

const emit = defineEmits<{
	close: [value: boolean | null]
}>()

type Show = 'generated' | 'all'

interface ShowOption {
	id: Show
	label: string
}

const showOptions: ShowOption[] = [
	{ id: 'generated', label: t('synaplan_integration', 'Generated') },
	{ id: 'all', label: t('synaplan_integration', 'All files') },
]

const opened = ref(true)
const loading = ref(true)
const adding = ref(false)
const show = ref<Show>('generated')
const search = ref('')
const files = ref<PickedFile[]>([])
const picked = ref<Record<number, PickedFile>>({})
const page = ref(1)
const pages = ref(1)
const failure = ref<Failure | null>(null)
const openSynaplanUrl = ref('')
const addedAny = ref(false)
const baseUrl = generateUrl('/apps/synaplan_integration')
let searchTimer: ReturnType<typeof setTimeout> | undefined
let requestNumber = 0

const pickedCount = computed(() => Object.keys(picked.value).length)
const addLabel = computed(() =>
	pickedCount.value === 0
		? t('synaplan_integration', 'Add files')
		: n(
				'synaplan_integration',
				'Add %n file',
				'Add %n files',
				pickedCount.value,
			),
)

/**
 * Close the dialog.
 */
function onClose() {
	opened.value = false
	emit('close', addedAny.value ? true : null)
}

/**
 * Check or uncheck a row. Checked files stay checked when the filter or
 * the search changes.
 *
 * @param file The row
 * @param checked Whether it is now checked
 */
function toggle(file: PickedFile, checked: boolean) {
	const next = { ...picked.value }
	if (checked) {
		next[file.id] = file
	} else {
		delete next[file.id]
	}
	picked.value = next
}

/**
 * The person's Synaplan files, newest first, filtered in Synaplan so the
 * filter spans every page. Further pages append.
 *
 * @param more true to fetch the next page
 */
async function load(more = false) {
	const requested = more ? page.value + 1 : 1
	const current = ++requestNumber
	loading.value = true
	if (!more) {
		failure.value = null
	}
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/from-synaplan`, {
			params: {
				page: requested,
				show: show.value,
				search: search.value.trim(),
			},
		})
		if (current !== requestNumber) {
			return
		}
		const incoming: PickedFile[] = Array.isArray(data.files) ? data.files : []
		files.value = more ? [...files.value, ...incoming] : incoming
		page.value = typeof data.page === 'number' ? data.page : requested
		pages.value = typeof data.pages === 'number' ? data.pages : 1
		openSynaplanUrl.value =
			typeof data.openSynaplanUrl === 'string' ? data.openSynaplanUrl : ''
	} catch (error: unknown) {
		if (current === requestNumber) {
			failure.value = failureFromError(error)
		}
	} finally {
		if (current === requestNumber) {
			loading.value = false
		}
	}
}

/**
 * Show the new files in the folder that is open without a reload.
 *
 * @param paths Paths of the new files below the person's files
 */
async function announce(paths: string[]) {
	const client = getClient()
	for (const path of paths) {
		try {
			const stat = await client.stat(`${getRootPath()}${path}`, {
				details: true,
				data: getDefaultPropfind(),
			})
			emitEvent(
				'files:node:created',
				resultToNode('data' in stat ? stat.data : stat),
			)
		} catch {
			// The file is there; it shows after the next reload.
		}
	}
}

/**
 * Copy the checked files into the folder that is open.
 */
async function add() {
	if (adding.value || pickedCount.value === 0) {
		return
	}
	adding.value = true
	failure.value = null
	try {
		const result = await addFiles(Object.values(picked.value), props.folder)
		if (result.added.length > 0) {
			addedAny.value = true
			const folder = folderLabel(props.folder)
			showSuccess(
				result.added.length === 1
					? t(
							'synaplan_integration',
							'Synaplan copied {name} to {folder}.',
							{
								name: result.added[0].name,
								folder,
							},
						)
					: n(
							'synaplan_integration',
							'Synaplan copied %n file to {folder}.',
							'Synaplan copied %n files to {folder}.',
							result.added.length,
							{ folder },
						),
			)
			await announce(result.added.map((file) => file.path))
		}
		if (result.failure) {
			const left = Object.values(picked.value).slice(result.added.length)
			picked.value = Object.fromEntries(left.map((file) => [file.id, file]))
			failure.value = result.failure
			return
		}
		onClose()
	} finally {
		adding.value = false
	}
}

watch(show, () => load())
watch(search, () => {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(() => load(), 300)
})

onMounted(load)
onBeforeUnmount(() => clearTimeout(searchTimer))
</script>

<style scoped>
.synaplan-from-modal {
	padding: 8px 0 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.filters {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.show {
	flex: 0 0 180px;
	min-width: 0;
}

.search {
	flex: 1 1 auto;
}

.hint,
.saved-into {
	margin: 0;
	color: var(--color-main-text, #222);
}

.empty {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
}

.files {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	max-height: 320px;
	overflow: auto;
}

.row :deep(.checkbox-radio-switch__text) {
	display: flex;
	flex: 1 1 auto;
	min-width: 0;
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

.meta {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast, #6b6b6b);
}

.more {
	align-self: flex-start;
}
</style>
