<template>
	<div class="sp-personal">
		<NcSettingsSection
			:name="t('synaplan_integration', 'Synaplan')"
			:description="
				t(
					'synaplan_integration',
					'Connect your Synaplan account so Nextcloud AI actions run as you.',
				)
			">
			<NcNoteCard v-if="banner" :type="bannerType">
				{{ banner }}
			</NcNoteCard>

			<p v-if="loading" class="sp-hint">
				{{ t('synaplan_integration', 'Loading...') }}
			</p>

			<template v-else>
				<p v-if="connectedLabel" class="sp-status-line">
					{{ connectedLabel }}
				</p>
				<p v-else-if="modeHint" class="sp-status-line">
					{{ modeHint }}
				</p>
				<p v-else class="sp-status-line">
					{{ t('synaplan_integration', 'Not connected') }}
				</p>

				<div v-if="canConnect || linked" class="sp-actions">
					<a v-if="canConnect" class="sp-btn-primary" :href="startUrl">
						{{ t('synaplan_integration', 'Connect Synaplan') }}
					</a>
					<span
						v-else-if="linked"
						role="button"
						tabindex="0"
						class="sp-btn-danger"
						@click="!busy && disconnect()"
						@keydown.enter="!busy && disconnect()">
						{{
							busy
								? t('synaplan_integration', 'Disconnecting…')
								: t('synaplan_integration', 'Disconnect')
						}}
					</span>
				</div>
			</template>
		</NcSettingsSection>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

interface LinkStatus {
	mode?: string
	link_available?: boolean
	linked?: { email: string; since: string } | null
	kind?: 'linked' | 'provisioned' | null
}

const baseUrl = generateUrl('/apps/synaplan_integration')
const startUrl = generateUrl('/apps/synaplan_integration/link/start')

const loading = ref(true)
const busy = ref(false)
const status = ref<LinkStatus>({})
const banner = ref('')
const bannerType = ref<'success' | 'error' | 'warning'>('success')

const linked = computed(
	() =>
		!!status.value.linked
		|| status.value.kind === 'linked'
		|| status.value.kind === 'provisioned',
)

const canConnect = computed(
	() =>
		status.value.mode === 'link'
		&& !linked.value
		&& !!status.value.link_available,
)

const modeHint = computed(() => {
	if (linked.value) {
		return ''
	}
	if (status.value.mode === 'shared') {
		return t(
			'synaplan_integration',
			'Your administrator connects Nextcloud to Synaplan for everyone. You do not need to link an account.',
		)
	}
	if (status.value.mode === 'provision') {
		return t(
			'synaplan_integration',
			'Activate AI from a Files action. Your administrator creates the account.',
		)
	}
	if (status.value.mode === 'link' && !status.value.link_available) {
		return t(
			'synaplan_integration',
			'Your administrator still needs to register this Nextcloud with Synaplan.',
		)
	}
	return ''
})

const connectedLabel = computed(() => {
	const row = status.value.linked
	if (!row && !linked.value) {
		return ''
	}
	const email = row?.email || ''
	const since = row?.since ? formatDate(row.since) : ''
	if (email && since) {
		return t('synaplan_integration', 'Connected as {email} since {date}', {
			email,
			date: since,
		})
	}
	if (email) {
		return t('synaplan_integration', 'Connected as {email}', { email })
	}
	return t('synaplan_integration', 'Connected')
})

/**
 * Human-readable date for the connection timestamp.
 * @param {string} iso ISO timestamp
 */
function formatDate(iso: string): string {
	const d = new Date(iso)
	return Number.isNaN(d.getTime()) ? iso : d.toLocaleString()
}

/**
 * Load link status from the server.
 */
async function loadStatus() {
	loading.value = true
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/link/status`)
		status.value = data || {}
	} catch {
		banner.value = t(
			'synaplan_integration',
			'Could not load Synaplan connection.',
		)
		bannerType.value = 'error'
	} finally {
		loading.value = false
	}
}

/**
 * Disconnect this Nextcloud user from the linked Synaplan account.
 */
async function disconnect() {
	busy.value = true
	try {
		await axios.post(`${baseUrl}/link/disconnect`)
		status.value = { ...status.value, linked: null, kind: null }
		banner.value = t(
			'synaplan_integration',
			'Disconnected. Connect again when you want to use Synaplan here.',
		)
		bannerType.value = 'success'
	} catch {
		banner.value = t('synaplan_integration', 'Could not disconnect. Try again.')
		bannerType.value = 'error'
	} finally {
		busy.value = false
	}
}

/**
 * Read ?linked=1 / ?link_error= from the personal-settings URL.
 */
function applyQueryBanner() {
	const params = new URLSearchParams(window.location.search)
	if (params.get('linked') === '1') {
		banner.value = t(
			'synaplan_integration',
			'Connected. The next Files action will use your Synaplan account.',
		)
		bannerType.value = 'success'
		return
	}
	const error = params.get('link_error')
	if (error === 'state') {
		banner.value = t(
			'synaplan_integration',
			'That connection expired. Please try again.',
		)
		bannerType.value = 'error'
	} else if (error === 'instance_pending') {
		banner.value = t(
			'synaplan_integration',
			'This Nextcloud is waiting for approval by the Synaplan administrator.',
		)
		bannerType.value = 'warning'
	} else if (error) {
		banner.value = t(
			'synaplan_integration',
			'Could not connect. Please try again.',
		)
		bannerType.value = 'error'
	}
}

onMounted(async () => {
	applyQueryBanner()
	await loadStatus()
})
</script>

<style scoped>
.sp-personal {
	padding: 0 0 24px;
}

.sp-hint,
.sp-status-line {
	font-size: 0.95em;
	color: var(--color-main-text, #222);
	margin: 0 0 12px;
}

.sp-hint {
	color: var(--color-text-maxcontrast, #767676);
}

.sp-actions {
	display: flex;
	gap: 12px;
	margin-top: 8px;
}

.sp-btn-primary,
.sp-btn-danger {
	display: inline-block;
	padding: 10px 28px;
	border-radius: 22px;
	font-size: 0.95em;
	font-weight: 600;
	cursor: pointer;
	line-height: 1.2;
	text-decoration: none;
	user-select: none;
}

.sp-btn-primary {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}

.sp-btn-primary:hover {
	text-decoration: none;
}

.sp-btn-danger {
	background: transparent;
	color: var(--color-error, #e9322d);
	border: 2px solid var(--color-error, #e9322d);
}
</style>
