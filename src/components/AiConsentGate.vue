<template>
	<div v-if="blocked" class="ai-consent-gate">
		<NcNoteCard type="warning" :heading="t('synaplan_integration', 'Activate AI')">
			<p class="ai-consent-text">
				{{
					t(
						'synaplan_integration',
						'To use the AI features, a personal AI account is created for you on the Synaplan server. Your prompts and the documents you choose to share are sent there to generate answers. Your data stays separate from other users.',
					)
				}}
			</p>
			<div class="ai-consent-actions">
				<NcButton
					type="primary"
					:disabled="saving"
					@click="activate">
					{{
						saving
							? t('synaplan_integration', 'Activating…')
							: t('synaplan_integration', 'Activate AI')
					}}
				</NcButton>
			</div>
		</NcNoteCard>
	</div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

const emit = defineEmits<{
	// Fired whenever the "AI is blocked until the user activates it" state changes.
	'blocked-change': [value: boolean]
	// Fired once the user has activated AI.
	granted: []
}>()

const baseUrl = generateUrl('/apps/synaplan_integration')

const required = ref(false)
const granted = ref(false)
const saving = ref(false)
const blocked = ref(false)

function refreshBlocked() {
	blocked.value = required.value && !granted.value
	emit('blocked-change', blocked.value)
}

async function loadStatus() {
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/ai-consent`)
		required.value = !!data.required
		granted.value = !!data.granted
	} catch {
		// If the status can't be loaded, don't block the UI — the backend still
		// gates provisioning, so nothing is created without consent anyway.
		required.value = false
		granted.value = false
	}
	refreshBlocked()
}

async function activate() {
	saving.value = true
	try {
		const { data } = await axios.post(`${baseUrl}/api/v1/ai-consent`, {
			granted: true,
		})
		granted.value = !!data.granted
		refreshBlocked()
		if (granted.value) {
			emit('granted')
		}
	} catch {
		// Leave blocked; the user can retry.
	} finally {
		saving.value = false
	}
}

onMounted(loadStatus)
</script>

<style scoped>
.ai-consent-gate {
	margin-bottom: 12px;
}

.ai-consent-text {
	margin: 0 0 8px;
}

.ai-consent-actions {
	display: flex;
	gap: 8px;
}
</style>
