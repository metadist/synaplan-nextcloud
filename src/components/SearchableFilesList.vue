<template>
	<div
		v-if="files.length > 0"
		class="sp-searchable-card"
		data-testid="synaplan-searchable-files">
		<div class="sp-searchable-head">
			<strong>{{ t('synaplan_integration', 'Searchable by AI') }}</strong>
			<a
				v-if="openSynaplanUrl"
				:href="openSynaplanUrl"
				target="_blank"
				rel="noopener noreferrer"
				class="sp-searchable-link">
				{{ t('synaplan_integration', 'Open in {app}', { app: 'Synaplan' }) }}
				↗
			</a>
		</div>
		<ul class="sp-searchable-list">
			<li v-for="file in files" :key="file.id" class="sp-searchable-row">
				<span class="sp-searchable-bird" v-html="birdSvg" />
				<div class="sp-searchable-meta">
					<a
						v-if="file.openUrl"
						:href="file.openUrl"
						class="sp-searchable-name">
						{{ file.path || file.name }}
					</a>
					<span v-else class="sp-searchable-name">
						{{ file.path || file.name }}
					</span>
					<span v-if="file.knowledgeFolder" class="sp-searchable-folder">
						{{ file.knowledgeFolder }}
					</span>
				</div>
				<button
					v-if="file.fileId > 0"
					type="button"
					class="sp-searchable-remove"
					:disabled="removing === file.fileId"
					@click="removeFile(file.fileId)">
					{{ t('synaplan_integration', 'Remove from Synaplan') }}
				</button>
			</li>
		</ul>
	</div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import { BIRD_SVG } from '../cloud_state/glyphs'

interface SearchableRow {
	id: number
	fileId: number
	name: string
	path: string
	knowledgeFolder: string
	openUrl: string
}

const baseUrl = generateUrl('/apps/synaplan_integration')
const birdSvg = BIRD_SVG
const files = ref<SearchableRow[]>([])
const openSynaplanUrl = ref('')
const removing = ref<number | null>(null)

/**
 * Load the person's searchable Nextcloud files.
 */
async function load(): Promise<void> {
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/cloud/searchable`)
		if (data?.success && Array.isArray(data.files)) {
			files.value = data.files
			openSynaplanUrl.value = data.openSynaplanUrl || ''
		}
	} catch {
		// Quiet on the app page when not connected yet.
	}
}

/**
 * Remove one file from Synaplan.
 *
 * @param {number} fileId Nextcloud file id
 */
async function removeFile(fileId: number): Promise<void> {
	removing.value = fileId
	try {
		const { data } = await axios.delete(`${baseUrl}/api/v1/knowledge/${fileId}`)
		if (data?.success) {
			files.value = files.value.filter((f) => f.fileId !== fileId)
		}
	} catch {
		// Keep row on failure.
	} finally {
		removing.value = null
	}
}

onMounted(() => {
	load()
})
</script>

<style scoped>
.sp-searchable-card {
	width: 100%;
	max-width: 800px;
	margin: 0 auto 24px;
	padding: 16px 20px;
	border-radius: 14px;
	background: var(--color-background-dark, rgba(0, 0, 0, 0.35));
	border: 1px solid var(--color-border, rgba(255, 255, 255, 0.12));
	box-sizing: border-box;
}

.sp-searchable-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 10px;
}

.sp-searchable-link {
	font-size: 0.85em;
	font-weight: 600;
	color: var(--color-primary-element, #0082c9);
	text-decoration: none;
}

.sp-searchable-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sp-searchable-row {
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 0.9em;
}

.sp-searchable-bird {
	display: inline-flex;
	color: light-dark(#059669, #34d399);
	flex-shrink: 0;
}

.sp-searchable-meta {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 1;
	min-width: 0;
}

.sp-searchable-name {
	font-weight: 500;
	color: var(--color-main-text, #222);
	text-decoration: none;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sp-searchable-folder {
	font-size: 0.8em;
	padding: 2px 8px;
	border-radius: 999px;
	background: var(--color-background-hover, rgba(127, 127, 127, 0.15));
	color: var(--color-text-maxcontrast, #767676);
}

.sp-searchable-remove {
	background: transparent;
	border: none;
	color: var(--color-error, #d93025);
	font-size: 0.85em;
	font-weight: 600;
	cursor: pointer;
}
</style>
