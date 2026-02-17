<template>
	<div class="synaplan-research-wrapper">
		<div class="synaplan-research">
			<div class="research-header">
				<h2>{{ t('synaplan_integration', 'Synaplan AI Assistant') }}</h2>
				<p class="subtitle">
					{{
						t(
							'synaplan_integration',
							'Ask anything — powered by Synaplan',
						)
					}}
				</p>
			</div>

			<!-- Controls bar -->
			<div class="controls-bar">
				<div class="control-group">
					<label class="control-label">{{
						t('synaplan_integration', 'Knowledge')
					}}</label>
					<NcSelect
						v-model="selectedGroup"
						:options="groupOptions"
						:placeholder="
							t('synaplan_integration', 'No knowledge group')
						"
						:clearable="true"
						:loading="loadingGroups"
						class="control-select" />
				</div>
				<div class="control-group">
					<label class="control-label">{{
						t('synaplan_integration', 'Model')
					}}</label>
					<NcSelect
						v-model="selectedModel"
						:options="modelOptions"
						:reduce="(m: ModelOption) => m"
						label="label"
						:placeholder="t('synaplan_integration', 'Default model')"
						:clearable="true"
						:loading="loadingModels"
						class="control-select" />
				</div>
			</div>

			<!-- Messages -->
			<div ref="messagesContainer" class="messages">
				<div v-if="messages.length === 0 && !loading" class="empty-state">
					<p v-if="selectedGroup">
						{{
							t(
								'synaplan_integration',
								'Ask questions about your "{group}" knowledge base.',
								{ group: selectedGroup },
							)
						}}
					</p>
					<p v-else>
						{{
							t(
								'synaplan_integration',
								'Start a conversation by typing a question below.',
							)
						}}
					</p>
				</div>
				<div
					v-for="(msg, idx) in messages"
					:key="idx"
					:class="['message', msg.role]">
					<div class="message-content" v-text="msg.text" />
				</div>
				<div v-if="loading" class="message assistant">
					<div class="message-content loading-dots">
						{{ t('synaplan_integration', 'Thinking...') }}
					</div>
				</div>
			</div>

			<!-- Error -->
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<!-- Input -->
			<div class="chat-input">
				<NcTextField
					v-model="inputMessage"
					:placeholder="t('synaplan_integration', 'Ask a question...')"
					:disabled="loading"
					@keydown.enter="sendMessage" />
				<NcButton
					type="primary"
					:disabled="loading || !inputMessage.trim()"
					@click="sendMessage">
					{{ t('synaplan_integration', 'Send') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, onMounted, nextTick } from 'vue'
import axios, { isAxiosError } from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

interface ChatMessage {
	role: 'user' | 'assistant'
	text: string
}

interface ModelOption {
	id: number
	label: string
	service: string
}

const loading = ref(false)
const loadingGroups = ref(false)
const loadingModels = ref(false)
const error = ref('')
const inputMessage = ref('')
const messages = ref<ChatMessage[]>([])
const messagesContainer = ref<HTMLElement | null>(null)

// Controls
const selectedGroup = ref<string | null>(null)
const groupOptions = ref<string[]>([])
const selectedModel = ref<ModelOption | null>(null)
const modelOptions = ref<ModelOption[]>([])

const baseUrl = generateUrl('/apps/synaplan_integration')

/**
 * Load available groups from Synaplan.
 */
async function loadGroups() {
	loadingGroups.value = true
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/knowledge/groups`)
		if (data.success && Array.isArray(data.groups)) {
			groupOptions.value = data.groups.map((g: { name: string }) => g.name)
		}
	} catch {
		// Groups are optional
	} finally {
		loadingGroups.value = false
	}
}

/**
 * Load available chat models from Synaplan.
 */
async function loadModels() {
	loadingModels.value = true
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/knowledge/models`)
		if (data.success && Array.isArray(data.models)) {
			modelOptions.value = data.models.map(
				(m: { id: number; name: string; service: string }) => ({
					id: m.id,
					label: `${m.name} (${m.service})`,
					service: m.service,
				}),
			)
		}
	} catch {
		// Models are optional — will use default
	} finally {
		loadingModels.value = false
	}
}

/**
 * Scroll messages container to bottom.
 */
async function scrollToBottom() {
	await nextTick()
	if (messagesContainer.value) {
		messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
	}
}

/**
 * Send a research question to Synaplan.
 */
async function sendMessage() {
	const text = inputMessage.value.trim()
	if (!text || loading.value) return

	messages.value.push({ role: 'user', text })
	inputMessage.value = ''
	loading.value = true
	error.value = ''
	await scrollToBottom()

	try {
		const payload: Record<string, unknown> = { message: text }

		if (selectedGroup.value) {
			payload.groupKey = selectedGroup.value
		}
		if (selectedModel.value) {
			payload.modelId = selectedModel.value.id
		}

		const { data } = await axios.post(`${baseUrl}/api/v1/chat`, payload)

		if (data.success) {
			messages.value.push({ role: 'assistant', text: data.response })
		} else {
			error.value = data.error || t('synaplan_integration', 'Request failed')
		}
	} catch (err: unknown) {
		error.value = isAxiosError(err)
			? err.response?.data?.error || err.message
			: 'Unknown error'
	} finally {
		loading.value = false
		await scrollToBottom()
	}
}

onMounted(() => {
	loadGroups()
	loadModels()
})
</script>

<style scoped>
.synaplan-research-wrapper {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: calc(100vh - 50px);
	padding: 24px;
	box-sizing: border-box;
}

.synaplan-research {
	display: flex;
	flex-direction: column;
	width: 100%;
	max-width: 800px;
	height: calc(100vh - 100px);
	max-height: 900px;
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: 16px;
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.15);
	padding: 24px;
	overflow: hidden;
}

.research-header {
	text-align: center;
	margin-bottom: 12px;
	flex-shrink: 0;
}

.research-header h2 {
	margin: 0;
	font-size: 1.4em;
	font-weight: 700;
	color: var(--color-main-text, #222);
}

.subtitle {
	color: var(--color-text-maxcontrast, #767676);
	margin: 4px 0 0;
	font-size: 0.9em;
}

/* Controls bar */
.controls-bar {
	display: flex;
	gap: 16px;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border, #e0e0e0);
	margin-bottom: 8px;
	flex-shrink: 0;
}

.control-group {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.control-label {
	font-size: 0.8em;
	font-weight: 600;
	color: var(--color-text-maxcontrast, #767676);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.control-select {
	width: 100%;
}

.control-select :deep(.v-select) {
	width: 100%;
}

/* Messages */
.messages {
	flex: 1;
	overflow-y: auto;
	padding: 12px 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 0;
}

.empty-state {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
	color: var(--color-text-maxcontrast, #767676);
	font-size: 1.05em;
}

.message {
	max-width: 80%;
	padding: 12px 16px;
	border-radius: 12px;
	line-height: 1.6;
}

.message.user {
	align-self: flex-end;
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}

.message.assistant {
	align-self: flex-start;
	background: var(--color-background-dark, #ededed);
	color: var(--color-main-text, #222);
}

.message-content {
	white-space: pre-wrap;
	word-break: break-word;
}

.loading-dots {
	opacity: 0.7;
	font-style: italic;
}

.chat-input {
	display: flex;
	gap: 10px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border, #e0e0e0);
	flex-shrink: 0;
	align-items: center;
}

.chat-input :deep(.input-field) {
	flex: 1;
	min-width: 0;
}

.chat-input :deep(input) {
	width: 100%;
}
</style>
