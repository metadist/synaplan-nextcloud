<template>
	<div v-if="blocked" class="ai-consent-gate">
		<NcNoteCard :type="conflict ? 'warning' : 'info'" :heading="heading">
			<p class="ai-consent-text">
				{{ bodyText }}
			</p>
			<div class="ai-consent-actions">
				<NcButton
					v-if="showConnect"
					type="primary"
					:disabled="saving"
					@click="connect">
					{{ t('synaplan_integration', 'Connect my Synaplan account') }}
				</NcButton>
				<NcButton
					v-if="showCreate"
					:type="showConnect ? 'secondary' : 'primary'"
					:disabled="saving"
					@click="createAccount">
					{{
						saving
							? t('synaplan_integration', 'Creating…')
							: t('synaplan_integration', 'Create one for me')
					}}
				</NcButton>
				<NcButton
					v-if="showActivate"
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
import { computed, onMounted, ref } from 'vue'
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
const startUrl = generateUrl('/apps/synaplan_integration/link/start')

const required = ref(false)
const granted = ref(false)
const saving = ref(false)
const blocked = ref(false)
const mode = ref('shared')
const autoProvision = ref(false)
const linkAvailable = ref(false)
const conflict = ref(false)

const isLink = computed(() => mode.value === 'link')
const showConnect = computed(
	() => isLink.value && (linkAvailable.value || conflict.value),
)
const showCreate = computed(
	() => isLink.value && autoProvision.value && !conflict.value,
)
const showActivate = computed(() => !isLink.value)

const heading = computed(() =>
	conflict.value
		? t('synaplan_integration', 'An account with this email already exists')
		: isLink.value
			? t('synaplan_integration', 'Connect Synaplan')
			: t('synaplan_integration', 'Activate AI'),
)

const bodyText = computed(() => {
	if (conflict.value) {
		return t(
			'synaplan_integration',
			'An account with this email already exists — connect it.',
		)
	}
	if (isLink.value) {
		return t(
			'synaplan_integration',
			'Connect your Synaplan account so chat, files and knowledge here run as you. You will sign in once and confirm. Nothing is copied.',
		)
	}
	return t(
		'synaplan_integration',
		'To use the AI features, a personal AI account is created for you on the Synaplan server. Your prompts and the documents you choose to share are sent there to generate answers. Your data stays separate from other users.',
	)
})

/**
 * Recompute whether AI is blocked and notify the parent.
 */
function refreshBlocked() {
	blocked.value = required.value && !granted.value
	emit('blocked-change', blocked.value)
}

/**
 * Load consent and link-mode status for the current user.
 */
async function loadStatus() {
	try {
		const [consentRes, linkRes] = await Promise.all([
			axios.get(`${baseUrl}/api/v1/ai-consent`),
			axios.get(`${baseUrl}/api/v1/link/status`).catch(() => ({ data: {} })),
		])
		required.value = !!consentRes.data.required
		granted.value = !!consentRes.data.granted
		mode.value = linkRes.data.mode || 'shared'
		autoProvision.value = !!linkRes.data.auto_provision
		linkAvailable.value = !!linkRes.data.link_available
		if (linkRes.data.linked) {
			granted.value = true
		}
	} catch {
		// If the status can't be loaded, don't block the UI — the backend still
		// gates provisioning, so nothing is created without consent anyway.
		required.value = false
		granted.value = false
	}
	refreshBlocked()
}

/**
 * Start the Synaplan handshake in this tab.
 */
function connect() {
	window.location.href = startUrl
}

/**
 * Grant consent in provision mode (creates an account on first use).
 */
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

/**
 * Create a Synaplan account from the two-option gate (admin opt-in).
 */
async function createAccount() {
	saving.value = true
	conflict.value = false
	try {
		const { data } = await axios.post(`${baseUrl}/api/v1/ai-consent`, {
			granted: true,
			create_account: true,
		})
		if (data.conflict) {
			conflict.value = true
			return
		}
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
	flex-wrap: wrap;
	gap: 8px;
}
</style>
