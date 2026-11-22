<template>
	<NcDialog
		:open="opened"
		:name="t('synaplan_integration', 'Watch “{name}” with Synaplan', { name })"
		@update:open="onClose">
		<div class="synaplan-watch-modal">
			<AiConsentGate
				@blockedChange="blocked = $event"
				@granted="loadOptions" />
			<p v-if="loading">
				{{ t('synaplan_integration', 'Loading…') }}
			</p>
			<FailureNote v-else-if="failure" :failure="failure" />
			<template v-else-if="!started">
				<p class="sp-question">
					{{
						kind === 'file'
							? t(
									'synaplan_integration',
									'What should happen when this file changes?',
								)
							: t(
									'synaplan_integration',
									'What should happen with new files?',
								)
					}}
				</p>
				<NcCheckboxRadioSwitch
					v-model="preset"
					value="keep_searchable"
					name="sp-watch-preset"
					type="radio">
					{{
						kind === 'file'
							? t('synaplan_integration', 'Keep it searchable')
							: t('synaplan_integration', 'Keep them searchable')
					}}
				</NcCheckboxRadioSwitch>
				<label v-if="preset === 'keep_searchable'" class="sp-field">
					{{ t('synaplan_integration', 'Knowledge folder') }}
					<NcSelect
						v-model="groupKey"
						:options="folderOptions"
						:taggable="true"
						:clearable="false"
						:disabled="saving"
						@tag="onNewFolder" />
				</label>
				<NcCheckboxRadioSwitch
					v-model="preset"
					value="task"
					name="sp-watch-preset"
					type="radio"
					:disabled="tasks.length === 0">
					{{ t('synaplan_integration', 'Use one of my tasks') }}
				</NcCheckboxRadioSwitch>
				<p v-if="tasks.length === 0" class="sp-hint">
					{{
						t(
							'synaplan_integration',
							'You have no tasks you start yourself.',
						)
					}}
				</p>
				<label v-else-if="preset === 'task'" class="sp-field">
					{{ t('synaplan_integration', 'Use one of my tasks') }}
					<NcSelect
						v-model="task"
						:options="tasks"
						label="name"
						:clearable="false"
						:disabled="saving" />
				</label>
				<p class="sp-hint">
					{{ consequence }}
				</p>
				<template v-if="kind === 'folder' && existing > 0">
					<NcCheckboxRadioSwitch
						:modelValue="backfill"
						:disabled="saving"
						@update:modelValue="onBackfill">
						{{
							t(
								'synaplan_integration',
								'Also the files already in this folder ({count})',
								{ count: existing },
							)
						}}
					</NcCheckboxRadioSwitch>
					<p v-if="backfill && existing > MANY_FILES" class="sp-hint">
						{{
							t(
								'synaplan_integration',
								'Synaplan takes the {count} files one after the other. This can take about an hour.',
								{ count: existing },
							)
						}}
					</p>
				</template>
				<p v-if="kind === 'folder'" class="sp-hint">
					{{
						t(
							'synaplan_integration',
							'Everyone who can open this folder sees that it is watched.',
						)
					}}
				</p>
				<p
					v-if="!jobsOnSchedule"
					class="sp-hint"
					data-testid="synaplan-watch-jobs-note">
					{{
						t(
							'synaplan_integration',
							'Watched folders need background jobs that run on a schedule.',
						)
					}}
					{{
						t(
							'synaplan_integration',
							'Until your administrator sets that up, Synaplan only gets new files while someone has a page of this Nextcloud open.',
						)
					}}
				</p>
			</template>
			<p v-else>
				{{
					t('synaplan_integration', 'Synaplan is watching “{name}”.', {
						name,
					})
				}}
			</p>
		</div>
		<template #actions>
			<NcButton v-if="started" variant="primary" @click="onClose">
				{{ t('synaplan_integration', 'Done') }}
			</NcButton>
			<template v-else>
				<NcButton variant="tertiary" :disabled="saving" @click="onClose">
					{{ t('synaplan_integration', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="!failure"
					variant="primary"
					:disabled="loading || saving || blocked || !canStart"
					@click="start">
					{{ t('synaplan_integration', 'Start watching') }}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import type { Failure } from '../failures'

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import AiConsentGate from './AiConsentGate.vue'
import FailureNote from './FailureNote.vue'
import { describeFailure, failureFromError } from '../failures'

const props = defineProps<{
	fileId: string
	name: string
	kind: 'folder' | 'file'
}>()

const emit = defineEmits<{
	close: [value: boolean | null]
}>()

const FOLDER_NAME_LIMIT = 64
const MANY_FILES = 200

const opened = ref(true)
const loading = ref(true)
const saving = ref(false)
const started = ref(false)
const blocked = ref(false)
const failure = ref<Failure | null>(null)
const preset = ref<'keep_searchable' | 'task'>('keep_searchable')
const groupKey = ref('')
const folderOptions = ref<string[]>([])
const task = ref<{ id: number; name: string } | null>(null)
const tasks = ref<Array<{ id: number; name: string }>>([])
const existing = ref(0)
const jobsOnSchedule = ref(true)
const backfill = ref(true)
const backfillChosen = ref(false)

// Making a folder searchable usually means all of it; a task usually only
// the files to come. An explicit choice stays.
watch(preset, (value) => {
	if (!backfillChosen.value) {
		backfill.value = value === 'keep_searchable'
	}
})

const clip = (value: string): string => value.trim().slice(0, FOLDER_NAME_LIMIT)

const selectedTask = computed(() => task.value?.name ?? '')
const canStart = computed(() =>
	preset.value === 'keep_searchable'
		? groupKey.value.trim() !== ''
		: (task.value?.id ?? 0) > 0,
)
const consequence = computed(() => {
	const folder = groupKey.value.trim() || props.name
	if (props.kind === 'file') {
		return preset.value === 'task'
			? t(
					'synaplan_integration',
					'“{task}” will run for a new copy of this file.',
					{
						task: selectedTask.value,
					},
				)
			: t(
					'synaplan_integration',
					'New versions of this file become searchable by AI in your knowledge folder “{folder}”.',
					{ folder },
				)
	}
	return preset.value === 'task'
		? t(
				'synaplan_integration',
				'“{task}” will run for every new file in “{folder}”.',
				{
					task: selectedTask.value,
					folder: props.name,
				},
			)
		: t(
				'synaplan_integration',
				'New and changed files become searchable by AI in your knowledge folder “{folder}”.',
				{ folder },
			)
})

/**
 *
 */
function onClose() {
	opened.value = false
	emit('close', started.value ? true : null)
}

/**
 * Keep the person's own choice about the files already in the folder.
 *
 * @param value whether they go too
 */
function onBackfill(value: boolean) {
	backfillChosen.value = true
	backfill.value = value
}

/**
 * Take a new folder typed into the folder picker.
 *
 * @param tag the folder name
 */
function onNewFolder(tag: string) {
	const name = clip(tag)
	if (name !== '' && !folderOptions.value.includes(name)) {
		folderOptions.value.push(name)
	}
	groupKey.value = name
}

let optionsRequest = 0

/**
 *
 */
async function loadOptions() {
	const mine = ++optionsRequest
	loading.value = true
	failure.value = null
	const fallback = clip(`Nextcloud · ${props.name}`)
	groupKey.value = fallback
	folderOptions.value = fallback === '' ? [] : [fallback]
	try {
		const { data } = await axios.get(
			generateUrl('/apps/synaplan_integration/api/v1/watches/options'),
			{ params: props.kind === 'folder' ? { targetId: props.fileId } : {} },
		)
		if (mine !== optionsRequest) {
			return
		}
		if (!data.success) {
			failure.value = describeFailure(data.error, data.message)
			return
		}
		const loaded = Array.isArray(data.tasks) ? data.tasks : []
		tasks.value = loaded.filter(
			(item: { id?: unknown; name?: unknown }) =>
				typeof item?.id === 'number' && typeof item?.name === 'string',
		)
		task.value = tasks.value[0] ?? null
		jobsOnSchedule.value = data.jobsOnSchedule !== false
		existing.value =
			typeof data.existing === 'number' && data.existing > 0
				? data.existing
				: 0
		const folders = Array.isArray(data.folders) ? data.folders : []
		const names = folders
			.map((item: { group_key?: unknown }) =>
				typeof item?.group_key === 'string' ? clip(item.group_key) : '',
			)
			.filter((name: string) => name !== '')
		if (names.length > 0) {
			folderOptions.value = names
			groupKey.value = names[0]
		}
	} catch (error: unknown) {
		if (mine !== optionsRequest) {
			return
		}
		failure.value = failureFromError(error)
	} finally {
		if (mine === optionsRequest) {
			loading.value = false
		}
	}
}

onMounted(() => {
	loadOptions()
})

/**
 *
 */
async function start() {
	if (!canStart.value || saving.value) {
		return
	}
	saving.value = true
	failure.value = null
	try {
		const { data } = await axios.post(
			generateUrl('/apps/synaplan_integration/api/v1/watches'),
			{
				targetKind: props.kind,
				targetId: props.fileId,
				preset: preset.value,
				taskId: preset.value === 'task' ? task.value?.id : undefined,
				groupKey:
					preset.value === 'keep_searchable'
						? clip(groupKey.value)
						: undefined,
				backfill:
					props.kind === 'folder' && existing.value > 0 && backfill.value,
			},
		)
		if (!data.success) {
			failure.value = describeFailure(data.error, data.message)
			return
		}
		started.value = true
	} catch (error: unknown) {
		failure.value = failureFromError(error)
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.synaplan-watch-modal {
	padding: 8px 0;
	min-height: 120px;
}

.sp-question {
	margin: 0 0 12px;
}

.sp-field {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 8px 0 12px;
}

.sp-field :deep(.v-select) {
	width: 100%;
}

.sp-hint {
	margin: 8px 0 0;
	color: var(--color-text-maxcontrast);
}
</style>
