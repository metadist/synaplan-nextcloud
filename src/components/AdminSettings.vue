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
							'AI-powered document summarization, translation, knowledge base, and chat for Nextcloud.',
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
				<!-- Active environment switch (dev convenience) -->
				<div class="sp-field">
					<strong>{{
						t('synaplan_integration', 'Active environment')
					}}</strong>
					<p class="sp-hint">
						{{
							t(
								'synaplan_integration',
								'Flip the whole app between your live and local Synaplan backend. Each environment keeps its own URL and API key.',
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
										'The app is using the local backend.',
									)
								: t(
										'synaplan_integration',
										'The app is using the live backend.',
									)
						}}
					</p>
				</div>

				<h3 class="sp-subhead">
					{{ t('synaplan_integration', 'Live (production)') }}
				</h3>
				<div class="sp-field">
					<strong>{{ t('synaplan_integration', 'Synaplan URL') }}</strong>
					<p class="sp-hint">
						{{
							t(
								'synaplan_integration',
								'The URL of your Synaplan instance.',
							)
						}}
					</p>
					<div :style="inputWrapStyle">
						<NcTextField
							id="synaplan-url"
							v-model="synaplanUrl"
							placeholder="https://synaplan.example.com"
							:disabled="saving"
							:style="{ width: '100%' }" />
					</div>
				</div>

				<div :style="{ height: '20px' }" />

				<div class="sp-field">
					<strong>{{ t('synaplan_integration', 'API Key') }}</strong>
					<p class="sp-hint">
						{{
							t(
								'synaplan_integration',
								'Your Synaplan API key for authentication.',
							)
						}}
					</p>
					<div :style="inputWrapStyle">
						<NcTextField
							id="synaplan-api-key"
							v-model="apiKey"
							:placeholder="apiKeyMasked || 'sk_...'"
							type="password"
							:disabled="saving"
							:style="{ width: '100%' }" />
					</div>
					<div v-if="apiKeySet && !apiKey" :style="{ height: '16px' }" />
					<p v-if="apiKeySet && !apiKey" class="sp-status">
						{{
							t(
								'synaplan_integration',
								'API key is configured. Enter a new value to replace it.',
							)
						}}
					</p>
				</div>

				<h3 class="sp-subhead">
					{{ t('synaplan_integration', 'Local (development)') }}
				</h3>
				<div class="sp-field">
					<strong>{{
						t('synaplan_integration', 'Local Synaplan URL')
					}}</strong>
					<p class="sp-hint">
						{{
							t(
								'synaplan_integration',
								'Your local Synaplan dev backend, e.g. http://localhost:8000.',
							)
						}}
					</p>
					<div :style="inputWrapStyle">
						<NcTextField
							id="synaplan-url-local"
							v-model="synaplanUrlLocal"
							placeholder="http://localhost:8000"
							:disabled="saving"
							:style="{ width: '100%' }" />
					</div>
				</div>

				<div :style="{ height: '20px' }" />

				<div class="sp-field">
					<strong>{{ t('synaplan_integration', 'Local API Key') }}</strong>
					<p class="sp-hint">
						{{
							t(
								'synaplan_integration',
								'API key for your local Synaplan dev backend.',
							)
						}}
					</p>
					<div :style="inputWrapStyle">
						<NcTextField
							id="synaplan-api-key-local"
							v-model="apiKeyLocal"
							:placeholder="apiKeyLocalMasked || 'sk_...'"
							type="password"
							:disabled="saving"
							:style="{ width: '100%' }" />
					</div>
					<div
						v-if="apiKeyLocalSet && !apiKeyLocal"
						:style="{ height: '16px' }" />
					<p v-if="apiKeyLocalSet && !apiKeyLocal" class="sp-status">
						{{
							t(
								'synaplan_integration',
								'API key is configured. Enter a new value to replace it.',
							)
						}}
					</p>
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
							'Lets users enrich answers with their Synaplan memories. Requires the memory service (Qdrant) to be available in Synaplan.',
						)
					}}
				</p>

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

			<NcSettingsSection :name="t('synaplan_integration', 'Quick Links')">
				<ul class="sp-links">
					<li v-if="synaplanUrl">
						<a
							:href="synaplanUrl"
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
import { ref, computed, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl, imagePath } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

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
		apiKeyLocalSet.value = data.api_key_local_set || false
		apiKeyLocalMasked.value = data.api_key_local_masked || ''
		defaultLanguage.value =
			languageOptions.find((o) => o.id === data.default_language)
			?? languageOptions[0]
		useInterfaceLanguage.value = data.use_interface_language !== false
		enableMemories.value = data.enable_memories !== false
	} catch {
		showMessage('Failed to load settings.', 'error')
	}
}

/**
 * Save settings to backend.
 */
async function save() {
	saving.value = true
	message.value = ''
	try {
		await axios.put(`${baseUrl}/api/v1/settings`, {
			active_env: activeEnv.value,
			synaplan_url: synaplanUrl.value,
			api_key: apiKey.value,
			synaplan_url_local: synaplanUrlLocal.value,
			api_key_local: apiKeyLocal.value,
			default_language: defaultLanguage.value.id,
			use_interface_language: useInterfaceLanguage.value,
			enable_memories: enableMemories.value,
		})
		if (apiKey.value) {
			apiKeySet.value = true
			apiKey.value = ''
		}
		if (apiKeyLocal.value) {
			apiKeyLocalSet.value = true
			apiKeyLocal.value = ''
		}
		showMessage('Settings saved successfully.')
		await loadSettings()
	} catch {
		showMessage('Failed to save settings.', 'error')
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
			showMessage(
				`Connection successful! Status: ${data.status}.`
					+ (providers ? ` Active providers: ${providers}.` : ''),
			)
		} else {
			showMessage(`Connection failed: ${data.error}`, 'error')
		}
	} catch (err: unknown) {
		const errorMsg = err instanceof Error ? err.message : 'Unknown error'
		showMessage(`Connection test failed: ${errorMsg}`, 'error')
	} finally {
		testing.value = false
	}
}

onMounted(() => {
	loadSettings()
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
