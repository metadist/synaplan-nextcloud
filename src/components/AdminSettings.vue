<template>
	<div class="sp-root">
		<!-- Spacer to clear Nextcloud hamburger toggle -->
		<div :style="{ height: '56px' }" />

		<!-- Wrapper with left padding -->
		<div :style="{ paddingLeft: '24px' }">
			<!-- Branding header -->
			<div class="sp-header">
				<a
					href="https://www.synaplan.com"
					target="_blank"
					rel="noopener noreferrer"
					class="sp-bird-link">
					<img
						:src="birdUrl"
						alt="Synaplan"
						:style="{
							display: 'block',
							height: '48px',
							width: '34px',
							objectFit: 'contain',
						}"
						width="34"
						height="48" />
				</a>
				<h1
					:style="{
						fontSize: '30px',
						fontWeight: '700',
						color: 'var(--color-main-text, #222)',
						letterSpacing: '-0.02em',
						margin: '0 0 8px',
						padding: '0',
						lineHeight: '1.3',
					}">
					Synaplan
				</h1>
				<p class="sp-tagline">
					{{
						t(
							'synaplan_integration',
							'AI summaries, translations, search in your files and chat for Nextcloud.',
						)
					}}
				</p>
				<a
					href="https://www.synaplan.com"
					target="_blank"
					rel="noopener noreferrer"
					class="sp-website">
					www.synaplan.com ↗
				</a>
			</div>

			<NcSettingsSection
				:name="t('synaplan_integration', 'Connection Settings')"
				:description="
					t(
						'synaplan_integration',
						'Configure the connection to your Synaplan instance.',
					)
				">
				<!-- The radio switches which saved pair is on screen. Both pairs are kept. -->
				<div class="sp-field">
					<strong>{{
						t('synaplan_integration', 'Active environment')
					}}</strong>
					<p class="sp-hint">
						{{
							t(
								'synaplan_integration',
								'Each choice keeps its own addresses and key. Only the selected one is used.',
							)
						}}
					</p>
					<div class="sp-env-switch">
						<NcCheckboxRadioSwitch
							v-model="activeEnv"
							value="live"
							name="sp-active-env"
							type="radio"
							:disabled="saving">
							{{ t('synaplan_integration', 'Live (production)') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="activeEnv"
							value="local"
							name="sp-active-env"
							type="radio"
							:disabled="saving">
							{{ t('synaplan_integration', 'Local (development)') }}
						</NcCheckboxRadioSwitch>
					</div>
					<p class="sp-status">
						{{
							activeEnv === 'local'
								? t(
										'synaplan_integration',
										'Nextcloud is calling the local Synaplan.',
									)
								: t(
										'synaplan_integration',
										'Nextcloud is calling the live Synaplan.',
									)
						}}
					</p>
				</div>

				<div class="sp-field">
					<strong>{{
						t('synaplan_integration', 'Address this Nextcloud calls')
					}}</strong>
					<p class="sp-hint">
						{{
							activeEnv === 'local'
								? t(
										'synaplan_integration',
										'Inside Docker, this Nextcloud calls Synaplan at http://backend.',
									)
								: t(
										'synaplan_integration',
										'The address of your Synaplan, for example https://synaplan.example.com.',
									)
						}}
					</p>
					<div :style="inputWrapStyle">
						<NcTextField
							:id="serverUrlFieldId"
							v-model="serverUrlActive"
							:placeholder="serverUrlPlaceholder"
							:disabled="saving"
							:style="{ width: '100%' }" />
					</div>
				</div>

				<div :style="{ height: '20px' }" />

				<div class="sp-field">
					<strong>{{
						t(
							'synaplan_integration',
							'Address people open in the browser',
						)
					}}</strong>
					<p class="sp-hint">
						{{
							t(
								'synaplan_integration',
								'Leave empty when people open the same address. Inside Docker, people open http://localhost:5173.',
							)
						}}
					</p>
					<div :style="inputWrapStyle">
						<NcTextField
							id="synaplan-public-url"
							v-model="synaplanPublicUrlActive"
							:placeholder="publicUrlPlaceholder"
							:disabled="saving"
							:style="{ width: '100%' }" />
					</div>
				</div>

				<div :style="{ height: '20px' }" />

				<div class="sp-field">
					<strong>{{ t('synaplan_integration', 'API Key') }}</strong>
					<p class="sp-hint">
						{{ apiKeyHint }}
					</p>
					<div :style="inputWrapStyle">
						<NcTextField
							:id="apiKeyFieldId"
							v-model="apiKeyActive"
							:placeholder="apiKeyActiveMasked || 'sk_...'"
							type="password"
							:disabled="saving"
							:style="{ width: '100%' }" />
					</div>
					<div
						v-if="apiKeyActiveSet && !apiKeyActive"
						:style="{ height: '16px' }" />
					<p v-if="apiKeyActiveSet && !apiKeyActive" class="sp-status">
						{{
							t(
								'synaplan_integration',
								'API key is configured. Enter a new value to replace it.',
							)
						}}
					</p>
				</div>

				<div :style="{ height: '20px' }" />

				<div class="sp-field">
					<strong>{{
						t('synaplan_integration', 'Folders one person may allow')
					}}</strong>
					<p class="sp-hint">
						{{
							t(
								'synaplan_integration',
								'How many folders one person may name for Synaplan to read.',
							)
						}}
					</p>
					<div :style="inputWrapStyle">
						<NcTextField
							id="synaplan-read-folder-cap"
							v-model="readFolderCap"
							type="number"
							:disabled="saving"
							:style="{ width: '100%' }" />
					</div>
				</div>

				<div :style="{ height: '20px' }" />

				<!-- Buttons -->
				<div
					:style="{ display: 'flex', gap: '16px', margin: '12px 0 32px' }">
					<span
						role="button"
						tabindex="0"
						:style="primaryBtnStyle"
						@click="!saving && save()"
						@keydown.enter="!saving && save()"
						@keydown.space.prevent="!saving && save()">
						{{
							saving
								? t('synaplan_integration', 'Saving...')
								: t('synaplan_integration', 'Save')
						}}
					</span>
					<span
						role="button"
						tabindex="0"
						:style="secondaryBtnStyle"
						@click="!testing && testConnection()"
						@keydown.enter="!testing && testConnection()"
						@keydown.space.prevent="!testing && testConnection()">
						{{
							testing
								? t('synaplan_integration', 'Testing...')
								: t('synaplan_integration', 'Test connection')
						}}
					</span>
				</div>

				<!-- Status message -->
				<NcNoteCard v-if="message" :type="messageType">
					{{ message }}
				</NcNoteCard>
			</NcSettingsSection>

			<NcSettingsSection
				:name="t('synaplan_integration', 'Language & AI behaviour')"
				:description="
					t(
						'synaplan_integration',
						'Choose which language the AI answers in, and whether personal memories may be used in chat.',
					)
				">
				<div class="sp-field">
					<strong>{{
						t('synaplan_integration', 'Default answer language')
					}}</strong>
					<p class="sp-hint">
						{{
							t(
								'synaplan_integration',
								'The AI answers in this language unless the user asks for another one.',
							)
						}}
					</p>
					<div :style="inputWrapStyle">
						<NcSelect
							v-model="defaultLanguage"
							:options="languageOptions"
							label="label"
							:clearable="false"
							:disabled="saving"
							class="sp-lang-select" />
					</div>
				</div>

				<div :style="{ height: '12px' }" />

				<NcCheckboxRadioSwitch
					v-model="useInterfaceLanguage"
					type="switch"
					:disabled="saving">
					{{
						t(
							'synaplan_integration',
							'Use each user\u2019s Nextcloud interface language when supported',
						)
					}}
				</NcCheckboxRadioSwitch>
				<p class="sp-hint sp-switch-hint">
					{{
						t(
							'synaplan_integration',
							'When enabled, a user whose Nextcloud is set to German automatically gets German answers; the default language above is the fallback.',
						)
					}}
				</p>

				<div :style="{ height: '16px' }" />

				<NcCheckboxRadioSwitch
					v-model="enableMemories"
					type="switch"
					:disabled="saving">
					{{
						t('synaplan_integration', 'Allow personal memories in chat')
					}}
				</NcCheckboxRadioSwitch>
				<p class="sp-hint sp-switch-hint">
					{{
						t(
							'synaplan_integration',
							'Lets users enrich answers with their Synaplan memories. Synaplan\u2019s memory service must be available.',
						)
					}}
				</p>

				<div :style="{ height: '16px' }" />

				<AdminConnection
					:mode="mode"
					:link-auto-provision="linkAutoProvision"
					:has-admin-key="hasAdminKey"
					:saving="saving"
					@update:mode="mode = $event"
					@update:link-auto-provision="linkAutoProvision = $event" />
				<div :style="{ height: '20px' }" />

				<div :style="{ display: 'flex', gap: '16px' }">
					<span
						role="button"
						tabindex="0"
						:style="primaryBtnStyle"
						@click="!saving && save()"
						@keydown.enter="!saving && save()"
						@keydown.space.prevent="!saving && save()">
						{{
							saving
								? t('synaplan_integration', 'Saving...')
								: t('synaplan_integration', 'Save')
						}}
					</span>
				</div>
			</NcSettingsSection>

			<AdminRegistration
				:delivery-url="deliveryUrl"
				:mode="mode"
				:saving="saving"
				:link-registered="linkRegistered"
				:link-instance-id="linkInstanceId"
				:link-instance="linkInstance"
				:registering="registering"
				:primary-btn-style="primaryBtnStyle"
				:secondary-btn-style="secondaryBtnStyle"
				@update:delivery-url="deliveryUrl = $event"
				@register="registerInstance"
				@forget="forgetInstance" />

			<AdminAiUsers v-if="mode !== 'shared'" @notify="showMessage" />

			<NcSettingsSection :name="t('synaplan_integration', 'Quick Links')">
				<ul class="sp-links">
					<li v-if="openSynaplanHref">
						<a
							:href="openSynaplanHref"
							target="_blank"
							rel="noopener noreferrer"
							class="sp-link">
							🌐 {{ t('synaplan_integration', 'Open Synaplan') }}
						</a>
					</li>
					<li>
						<a
							href="https://www.synaplan.com"
							target="_blank"
							rel="noopener noreferrer"
							class="sp-link">
							📖 {{ t('synaplan_integration', 'Documentation') }}
						</a>
					</li>
					<li>
						<a
							href="https://github.com/metadist/synaplan-nextcloud/issues"
							target="_blank"
							rel="noopener noreferrer"
							class="sp-link">
							🐛 {{ t('synaplan_integration', 'Report Issue') }}
						</a>
					</li>
				</ul>
			</NcSettingsSection>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl, imagePath } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import AdminAiUsers from './AdminAiUsers.vue'
import AdminConnection from './AdminConnection.vue'
import AdminRegistration from './AdminRegistration.vue'

interface LanguageOption {
	id: string
	label: string
}

const birdUrl = imagePath('synaplan_integration', 'synaplan-bird.svg')

const activeEnv = ref<'live' | 'local'>('live')
const synaplanUrl = ref('')
const apiKey = ref('')
const apiKeySet = ref(false)
const apiKeyMasked = ref('')
const synaplanUrlLocal = ref('')
const synaplanPublicUrl = ref('')
const synaplanPublicUrlLocal = ref('')
const apiKeyLocal = ref('')
const apiKeyLocalSet = ref(false)
const apiKeyLocalMasked = ref('')
const saving = ref(false)
const testing = ref(false)
const message = ref('')
const messageType = ref<'success' | 'error' | 'warning'>('success')

// Language & AI behaviour
const languageOptions: LanguageOption[] = [
	{ id: 'en', label: 'English' },
	{ id: 'de', label: 'Deutsch' },
	{ id: 'fr', label: 'Français' },
	{ id: 'es', label: 'Español' },
	{ id: 'it', label: 'Italiano' },
	{ id: 'pt', label: 'Português' },
	{ id: 'nl', label: 'Nederlands' },
	{ id: 'pl', label: 'Polski' },
	{ id: 'tr', label: 'Türkçe' },
]
const defaultLanguage = ref<LanguageOption>(languageOptions[0])
const useInterfaceLanguage = ref(true)
const enableMemories = ref(true)
const mode = ref<'shared' | 'provision' | 'link'>('shared')
const linkAutoProvision = ref(false)
const linkRegistered = ref(false)
const linkInstanceId = ref('')
const deliveryUrl = ref('')
const readFolderCap = ref('20')
const linkInstance = ref<{ status?: string; host?: string; error?: string } | null>(
	null,
)
const registering = ref(false)

const hasAdminKey = computed(() =>
	activeEnv.value === 'local' ? apiKeyLocalSet.value : apiKeySet.value,
)

const publicUrlPlaceholder = computed(() =>
	activeEnv.value === 'local'
		? 'http://localhost:5173'
		: 'https://synaplan.example.com',
)

const synaplanPublicUrlActive = computed({
	get: () =>
		activeEnv.value === 'local'
			? synaplanPublicUrlLocal.value
			: synaplanPublicUrl.value,
	set: (value: string) => {
		if (activeEnv.value === 'local') {
			synaplanPublicUrlLocal.value = value
		} else {
			synaplanPublicUrl.value = value
		}
	},
})

const serverUrlFieldId = computed(() =>
	activeEnv.value === 'local' ? 'synaplan-url-local' : 'synaplan-url',
)

const serverUrlPlaceholder = computed(() =>
	activeEnv.value === 'local' ? 'http://backend' : 'https://synaplan.example.com',
)

const apiKeyFieldId = computed(() =>
	activeEnv.value === 'local' ? 'synaplan-api-key-local' : 'synaplan-api-key',
)

const serverUrlActive = computed({
	get: () =>
		activeEnv.value === 'local' ? synaplanUrlLocal.value : synaplanUrl.value,
	set: (value: string) => {
		if (activeEnv.value === 'local') {
			synaplanUrlLocal.value = value
		} else {
			synaplanUrl.value = value
		}
	},
})

const apiKeyActive = computed({
	get: () => (activeEnv.value === 'local' ? apiKeyLocal.value : apiKey.value),
	set: (value: string) => {
		if (activeEnv.value === 'local') {
			apiKeyLocal.value = value
		} else {
			apiKey.value = value
		}
	},
})

const apiKeyActiveSet = computed(() =>
	activeEnv.value === 'local' ? apiKeyLocalSet.value : apiKeySet.value,
)

const apiKeyActiveMasked = computed(() =>
	activeEnv.value === 'local' ? apiKeyLocalMasked.value : apiKeyMasked.value,
)

/** The address a browser can open: the public one when set, otherwise the one Nextcloud calls. */
const openSynaplanHref = computed(
	() => synaplanPublicUrlActive.value.trim() || serverUrlActive.value.trim(),
)

const apiKeyHint = computed(() =>
	mode.value === 'link'
		? t(
				'synaplan_integration',
				'Optional. Needed to create accounts for users who have none, and to show the activated-users table.',
			)
		: t('synaplan_integration', 'Your Synaplan API key for authentication.'),
)

const baseUrl = generateUrl('/apps/synaplan_integration')

/* Inline button styles — guarantees we beat Nextcloud's global CSS */
/* Input field wrapper — 25% wider than default, with vertical breathing room */
const inputWrapStyle: Record<string, string> = {
	width: '500px',
	maxWidth: '100%',
	margin: '4px 0',
}

const btnBase: Record<string, string> = {
	display: 'inline-block',
	padding: '10px 28px',
	borderRadius: '22px',
	fontSize: '0.95em',
	fontWeight: '600',
	cursor: 'pointer',
	lineHeight: '1.2',
	userSelect: 'none',
	textAlign: 'center',
	transition: 'opacity 0.15s, border-color 0.15s, background 0.15s',
	border: '2px solid transparent',
}

const primaryBtnStyle = computed(() => ({
	...btnBase,
	background: 'var(--color-primary-element, #0082c9)',
	color: 'var(--color-primary-element-text, #fff)',
	opacity: saving.value ? '0.5' : '1',
}))

const secondaryBtnStyle = computed(() => ({
	...btnBase,
	background: 'transparent',
	color: 'var(--color-main-text, #222)',
	borderColor: 'var(--color-border-dark, rgba(255, 255, 255, 0.25))',
	opacity: testing.value ? '0.5' : '1',
}))

/**
 * Display a temporary status message.
 * @param {string} text Message content
 * @param {'success'|'error'|'warning'} type Message severity
 */
function showMessage(
	text: string,
	type: 'success' | 'error' | 'warning' = 'success',
) {
	message.value = text
	messageType.value = type
	if (type === 'success') {
		setTimeout(() => {
			message.value = ''
		}, 5000)
	}
}

/**
 * Load settings from backend.
 */
async function loadSettings() {
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/settings`)
		activeEnv.value = data.active_env === 'local' ? 'local' : 'live'
		synaplanUrl.value = data.synaplan_url || ''
		apiKeySet.value = data.api_key_set || false
		apiKeyMasked.value = data.api_key_masked || ''
		synaplanUrlLocal.value = data.synaplan_url_local || ''
		synaplanPublicUrl.value = data.synaplan_public_url || ''
		synaplanPublicUrlLocal.value = data.synaplan_public_url_local || ''
		apiKeyLocalSet.value = data.api_key_local_set || false
		apiKeyLocalMasked.value = data.api_key_local_masked || ''
		defaultLanguage.value =
			languageOptions.find((o) => o.id === data.default_language)
			?? languageOptions[0]
		useInterfaceLanguage.value = data.use_interface_language !== false
		enableMemories.value = data.enable_memories !== false
		if (
			data.mode === 'link'
			|| data.mode === 'provision'
			|| data.mode === 'shared'
		) {
			mode.value = data.mode
		} else {
			mode.value = data.per_user_accounts === true ? 'provision' : 'shared'
		}
		linkAutoProvision.value = data.link_auto_provision === true
		linkRegistered.value = data.link_registered === true
		linkInstanceId.value = data.link_instance_id || ''
		linkInstance.value = data.link_instance || null
		deliveryUrl.value = data.delivery_url || ''
		readFolderCap.value = String(data.read_folder_cap || 20)
	} catch {
		showMessage(t('synaplan_integration', 'Failed to load settings.'), 'error')
	}
}

/**
 * Save settings to backend.
 */
async function save() {
	saving.value = true
	message.value = ''
	try {
		const { data } = await axios.put(`${baseUrl}/api/v1/settings`, {
			active_env: activeEnv.value,
			synaplan_url: synaplanUrl.value,
			api_key: apiKey.value,
			synaplan_url_local: synaplanUrlLocal.value,
			synaplan_public_url: synaplanPublicUrl.value,
			synaplan_public_url_local: synaplanPublicUrlLocal.value,
			api_key_local: apiKeyLocal.value,
			default_language: defaultLanguage.value.id,
			use_interface_language: useInterfaceLanguage.value,
			enable_memories: enableMemories.value,
			mode: mode.value,
			per_user_accounts: mode.value !== 'shared',
			link_auto_provision: linkAutoProvision.value,
			read_folder_cap: Number(readFolderCap.value) || 20,
			...(mode.value === 'link' || mode.value === 'provision'
				? { delivery_url: deliveryUrl.value }
				: {}),
		})
		if (apiKey.value) {
			apiKeySet.value = true
			apiKey.value = ''
		}
		if (apiKeyLocal.value) {
			apiKeyLocalSet.value = true
			apiKeyLocal.value = ''
		}
		if (data.delivery_error) {
			showMessage(
				t(
					'synaplan_integration',
					'Saved. Synaplan could not be told where to reach this Nextcloud. Try again.',
				),
				'warning',
			)
			await loadSettings()
			return
		}
		showMessage(t('synaplan_integration', 'Settings saved successfully.'))
		await loadSettings()
	} catch {
		showMessage(t('synaplan_integration', 'Failed to save settings.'), 'error')
	} finally {
		saving.value = false
	}
}

/**
 * Test connection to Synaplan.
 */
async function testConnection() {
	testing.value = true
	message.value = ''
	try {
		const { data } = await axios.post(`${baseUrl}/api/v1/settings/test`)
		if (data.success) {
			const providers = Object.entries(data.providers || {})
				.filter(([, v]) => v)
				.map(([k]) => k)
				.join(', ')
			let msg = t(
				'synaplan_integration',
				'Connection successful! Status: {status}.',
				{ status: data.status },
			)
			if (providers) {
				msg = t(
					'synaplan_integration',
					'Connection successful! Status: {status}. Active providers: {providers}.',
					{ status: data.status, providers },
				)
			}
			showMessage(msg)
		} else {
			showMessage(
				t('synaplan_integration', 'Connection failed: {error}', {
					error: data.error,
				}),
				'error',
			)
		}
	} catch (err: unknown) {
		let errorMsg = t('synaplan_integration', 'Unknown error')
		if (err instanceof Error) {
			errorMsg = err.message
		}
		showMessage(
			t('synaplan_integration', 'Connection test failed: {error}', {
				error: errorMsg,
			}),
			'error',
		)
	} finally {
		testing.value = false
	}
}

/**
 * Register this Nextcloud as a Synaplan platform instance.
 */
async function registerInstance() {
	registering.value = true
	try {
		const { data } = await axios.post(
			`${baseUrl}/api/v1/settings/register-instance`,
		)
		if (data.success) {
			linkRegistered.value = true
			linkInstanceId.value = data.instance_id || ''
			linkInstance.value = {
				status: data.status,
				host: data.host,
			}
			showMessage(
				t(
					'synaplan_integration',
					'This Nextcloud is registered with Synaplan.',
				),
			)
		} else {
			showMessage(
				data.error
					|| t(
						'synaplan_integration',
						'Could not register this Nextcloud.',
					),
				'error',
			)
		}
	} catch (err: unknown) {
		let errorMsg = t(
			'synaplan_integration',
			'Could not register this Nextcloud.',
		)
		if (err instanceof Error) {
			errorMsg = err.message
		}
		showMessage(errorMsg, 'error')
	} finally {
		registering.value = false
	}
}

/**
 * Forget the stored instance credentials.
 */
async function forgetInstance() {
	registering.value = true
	try {
		await axios.post(`${baseUrl}/api/v1/settings/forget-instance`)
		linkRegistered.value = false
		linkInstanceId.value = ''
		linkInstance.value = null
		showMessage(
			t('synaplan_integration', 'Registration forgotten on this Nextcloud.'),
		)
	} catch {
		showMessage(
			t('synaplan_integration', 'Could not forget the registration.'),
			'error',
		)
	} finally {
		registering.value = false
	}
}

let statusTimer: ReturnType<typeof setInterval> | null = null

/**
 * Refresh instance status while waiting for Synaplan approval.
 */
async function refreshInstanceStatus() {
	if (
		(mode.value !== 'link' && mode.value !== 'provision')
		|| !linkRegistered.value
	) {
		return
	}
	try {
		const { data } = await axios.get(
			`${baseUrl}/api/v1/settings/instance-status`,
		)
		if (data.instance) {
			linkInstance.value = data.instance
		}
	} catch {
		// Keep the last known status.
	}
}

onMounted(async () => {
	await loadSettings()
	statusTimer = setInterval(() => {
		if (linkInstance.value?.status === 'pending') {
			refreshInstanceStatus()
		}
	}, 8000)
})

onUnmounted(() => {
	if (statusTimer) {
		clearInterval(statusTimer)
	}
})
</script>

<style scoped>
.sp-root {
	padding: 0 0 40px 0;
}

/* Header */
.sp-header {
	padding: 0 0 20px;
}

.sp-bird-link {
	display: block;
	text-decoration: none;
	margin-bottom: 10px;
}

.sp-bird-link:hover {
	opacity: 0.85;
}

.sp-tagline {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 0.95em;
	line-height: 1.5;
	margin: 0 0 10px;
}

.sp-website {
	display: inline-block;
	color: var(--color-primary-element, #0082c9);
	font-size: 0.9em;
	font-weight: 500;
	text-decoration: none;
}

.sp-website:hover {
	text-decoration: underline;
}

/* Fields */
.sp-field {
	margin-bottom: 24px;
}

.sp-subhead {
	font-size: 0.8em;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast, #767676);
	margin: 8px 0 12px;
}

.sp-env-switch {
	display: flex;
	gap: 24px;
	flex-wrap: wrap;
	margin: 4px 0 8px;
}

.sp-field strong {
	display: block;
	margin-bottom: 4px;
}

.sp-hint {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #767676);
	margin: 0 0 8px;
}

.sp-status {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #767676);
	margin: 0;
}

.sp-switch-hint {
	margin: 4px 0 0 44px;
	max-width: 560px;
}

.sp-lang-select {
	width: 100%;
}

/* Quick links */
.sp-links {
	list-style: none;
	margin: 0;
	padding: 0;
}

.sp-links li {
	display: block;
	margin-bottom: 14px;
}

.sp-links li:last-child {
	margin-bottom: 0;
}

.sp-link {
	display: inline-block;
	padding: 8px 16px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-dark, rgba(255, 255, 255, 0.06));
	border: 1px solid var(--color-border, rgba(255, 255, 255, 0.12));
	text-decoration: none;
	color: var(--color-main-text, #222);
	font-size: 0.9em;
	font-weight: 500;
	transition:
		background 0.15s,
		border-color 0.15s;
}

.sp-link:hover {
	background: var(--color-background-hover, rgba(255, 255, 255, 0.1));
	border-color: var(--color-primary-element, #0082c9);
	text-decoration: none;
}
</style>
