<template>
	<div class="synaplan-research-wrapper">
		<div class="synaplan-research">
			<div class="research-header">
				<h2>{{ t('synaplan_integration', 'Synaplan AI Assistant') }}</h2>
				<p class="subtitle">
					{{ tagline }}
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

			<!-- Memory toggle (only when the memory service is reachable). -->
			<div v-if="memoryAvailable" class="memory-row">
				<NcCheckboxRadioSwitch v-model="useMemories" type="switch">
					{{ t('synaplan_integration', 'Use my memories') }}
				</NcCheckboxRadioSwitch>
				<span class="memory-hint">{{
					t(
						'synaplan_integration',
						'Personalise answers with what Synaplan remembers about you.',
					)
				}}</span>
			</div>

			<!-- Status row: tells the user up front which model/language is used
			     and what the assistant can do right now. -->
			<div class="status-row">
				<span
					class="status-chip"
					:title="t('synaplan_integration', 'Active model')">
					<span class="chip-icon">&#129302;</span>
					{{ activeModelLabel }}
				</span>
				<span
					v-if="languageName"
					class="status-chip"
					:title="t('synaplan_integration', 'Answer language')">
					<span class="chip-icon">&#127760;</span>
					{{ languageName }}
				</span>
				<span
					class="status-chip"
					:class="capabilities.image ? 'on' : 'off'"
					:title="t('synaplan_integration', 'Image generation')">
					<span class="chip-icon">&#127912;</span>
					{{
						capabilities.image
							? t('synaplan_integration', 'Images: /pic')
							: t('synaplan_integration', 'Images off')
					}}
				</span>
				<span
					class="status-chip"
					:class="capabilities.video ? 'on' : 'off'"
					:title="t('synaplan_integration', 'Video generation')">
					<span class="chip-icon">&#127909;</span>
					{{
						capabilities.video
							? t('synaplan_integration', 'Videos: /vid')
							: t('synaplan_integration', 'Videos off')
					}}
				</span>
				<span
					v-if="memoryAvailable && useMemories"
					class="status-chip on"
					:title="t('synaplan_integration', 'Memories')">
					<span class="chip-icon">&#129504;</span>
					{{ t('synaplan_integration', 'Memories on') }}
				</span>
				<span
					v-if="selectedGroup"
					class="status-chip on"
					:title="t('synaplan_integration', 'Knowledge base')">
					<span class="chip-icon">&#128218;</span>
					{{ selectedGroup }}
				</span>
			</div>

			<!-- How to add a knowledge folder (RAG group). In Nextcloud, groups are
			     created by adding documents to them via the file context menu. -->
			<p
				v-if="!loadingGroups && groupOptions.length === 0"
				class="knowledge-hint">
				{{
					t(
						'synaplan_integration',
						'No knowledge folders yet. Add documents to one via a file\u2019s menu → Synaplan → Add to AI Knowledge.',
					)
				}}
			</p>

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
					<p
						v-if="capabilities.image || capabilities.video"
						class="command-hints">
						{{
							t(
								'synaplan_integration',
								'Try /pic to generate images{videoHint}.',
								{
									videoHint: capabilities.video
										? t(
												'synaplan_integration',
												' or /vid for videos',
											)
										: '',
								},
							)
						}}
					</p>
				</div>
				<div
					v-for="(msg, idx) in messages"
					:key="idx"
					:class="['message', msg.role]">
					<NcRichText
						v-if="msg.text && msg.role === 'assistant'"
						class="message-content markdown-body"
						:text="msg.text"
						:use-markdown="true"
						:use-extended-markdown="true" />
					<div
						v-else-if="msg.text"
						class="message-content"
						v-text="msg.text" />
					<span
						v-if="msg.role === 'assistant' && msg.model"
						class="message-model"
						:title="
							t('synaplan_integration', 'Model used for this answer')
						">
						{{ msg.model }}
					</span>
					<div v-if="msg.media" class="media-content">
						<img
							v-if="msg.media.type === 'image'"
							v-show="!msg.media.failed"
							:src="msg.media.url"
							:alt="t('synaplan_integration', 'Generated image')"
							class="generated-image"
							@load="msg.media.loaded = true"
							@error="msg.media.failed = true" />
						<video
							v-else-if="msg.media.type === 'video'"
							v-show="!msg.media.failed"
							:src="msg.media.url"
							controls
							class="generated-video"
							@loadeddata="msg.media.loaded = true"
							@error="msg.media.failed = true">
							{{
								t(
									'synaplan_integration',
									'Your browser does not support video playback.',
								)
							}}
						</video>
						<div v-if="msg.media.failed" class="media-failed">
							{{
								t(
									'synaplan_integration',
									'The generated file could not be loaded, so it cannot be saved.',
								)
							}}
						</div>
						<div class="media-meta">
							<span class="media-provider"
								>{{ msg.media.provider }} /
								{{ msg.media.model }}</span
							>
							<NcButton
								v-if="
									!msg.media.saved
									&& msg.media.loaded
									&& !msg.media.failed
								"
								type="secondary"
								:disabled="msg.media.saving"
								@click="saveMedia(idx)">
								{{
									msg.media.saving
										? t('synaplan_integration', 'Saving...')
										: t(
												'synaplan_integration',
												'Save to Nextcloud',
											)
								}}
							</NcButton>
							<span v-else-if="msg.media.saved" class="save-success">
								{{
									t('synaplan_integration', 'Saved to {path}', {
										path: msg.media.savedPath ?? '',
									})
								}}
							</span>
						</div>
					</div>
				</div>
				<div v-if="loading && !streaming" class="message assistant">
					<div class="message-content loading-dots">
						{{ loadingText }}
					</div>
				</div>
			</div>

			<!-- Error -->
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<!-- Input -->
			<div class="chat-input">
				<div class="help-trigger-wrapper">
					<button
						class="help-trigger"
						:title="t('synaplan_integration', 'Show available commands')"
						@click="showHelp = !showHelp">
						?
					</button>
					<div v-if="showHelp" class="help-popover">
						<p class="help-title">
							{{ t('synaplan_integration', 'Available commands') }}
						</p>
						<p v-if="capabilities.image" class="help-command">
							<code>/pic [description]</code>
							{{ t('synaplan_integration', 'Generate an image') }}
						</p>
						<p v-if="capabilities.video" class="help-command">
							<code>/vid [description]</code>
							{{ t('synaplan_integration', 'Generate a video') }}
						</p>
						<p
							v-if="!capabilities.image && !capabilities.video"
							class="help-hint">
							{{
								t(
									'synaplan_integration',
									'No media commands available. Configure image or video models in Synaplan.',
								)
							}}
						</p>
						<p class="help-hint">
							{{
								t(
									'synaplan_integration',
									'Or just type a question to chat with the AI.',
								)
							}}
						</p>
					</div>
				</div>
				<NcTextField
					v-model="inputMessage"
					:placeholder="inputPlaceholder"
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
import { ref, computed, onMounted, nextTick } from 'vue'
import axios, { isAxiosError } from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRichText from '@nextcloud/vue/components/NcRichText'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

interface MediaInfo {
	url: string
	type: 'image' | 'video'
	provider: string
	model: string
	sourceUrl: string
	filename: string
	loaded?: boolean
	failed?: boolean
	saved?: boolean
	savedPath?: string
	saving?: boolean
}

interface ChatMessage {
	role: 'user' | 'assistant'
	text: string
	media?: MediaInfo
	// Model that actually produced this answer (captured from the stream).
	model?: string
}

interface ModelOption {
	id: number
	label: string
	service: string
	// Provider model id or name — what the OpenAI-compatible endpoint resolves.
	model: string
}

interface Capabilities {
	image: boolean
	video: boolean
}

const loading = ref(false)
const streaming = ref(false)
const loadingGroups = ref(false)
const loadingModels = ref(false)
const error = ref('')
const inputMessage = ref('')
const messages = ref<ChatMessage[]>([])
const messagesContainer = ref<HTMLElement | null>(null)
const loadingText = ref(t('synaplan_integration', 'Thinking...'))

const selectedGroup = ref<string | null>(null)
const groupOptions = ref<string[]>([])
const selectedModel = ref<ModelOption | null>(null)
const modelOptions = ref<ModelOption[]>([])
const capabilities = ref<Capabilities>({ image: false, video: false })
const showHelp = ref(false)

// Answer language + personal memories (loaded from the client-config endpoint).
const languageName = ref('')
const memoryAvailable = ref(false)
const useMemories = ref(false)
const appVersion = ref('')

const baseUrl = generateUrl('/apps/synaplan_integration')

const tagline = computed(() =>
	t('synaplan_integration', 'Ask anything{version} — powered by Synaplan', {
		version: appVersion.value ? ` v${appVersion.value}` : '',
	}),
)

const activeModelLabel = computed(
	() =>
		selectedModel.value?.label
		?? t('synaplan_integration', 'Server default model'),
)

const inputPlaceholder = computed(() => {
	if (capabilities.value.image && capabilities.value.video) {
		return t('synaplan_integration', 'Ask a question, or use /pic or /vid...')
	}
	if (capabilities.value.image) {
		return t('synaplan_integration', 'Ask a question, or use /pic...')
	}
	return t('synaplan_integration', 'Ask a question...')
})

/**
 *
 * @param text
 */
function parseCommand(text: string): {
	command: 'pic' | 'vid' | null
	prompt: string
} {
	const trimmed = text.trim()
	const picMatch = trimmed.match(/^\/pic\s+(.+)$/i)
	if (picMatch) {
		return { command: 'pic', prompt: picMatch[1].trim() }
	}
	const vidMatch = trimmed.match(/^\/vid(?:eo)?\s+(.+)$/i)
	if (vidMatch) {
		return { command: 'vid', prompt: vidMatch[1].trim() }
	}
	return { command: null, prompt: trimmed }
}

/**
 *
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
 *
 */
async function loadModels() {
	loadingModels.value = true
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/knowledge/models`)
		if (data.success && Array.isArray(data.models)) {
			modelOptions.value = data.models.map(
				(m: {
					id: number
					name: string
					service: string
					providerId?: string
				}) => ({
					id: m.id,
					label: `${m.name} (${m.service})`,
					service: m.service,
					model: m.providerId || m.name,
				}),
			)
		}
		if (data.capabilities) {
			capabilities.value = {
				image: !!data.capabilities.image,
				video: !!data.capabilities.video,
			}
		}
	} catch {
		// Models are optional — will use default
	} finally {
		loadingModels.value = false
	}
}

let stageTimer: ReturnType<typeof setInterval> | null = null

/**
 * Show staged progress while we wait for the first token, so the user sees what
 * the backend is doing (sorting → searching context → generating) instead of a
 * single static "Thinking…". Stops as soon as the answer starts streaming.
 */
function startThinkingStages() {
	stopThinkingStages()
	const stages: string[] = [t('synaplan_integration', 'Sorting your request…')]
	if (selectedGroup.value) {
		stages.push(t('synaplan_integration', 'Searching the knowledge base…'))
	}
	if (useMemories.value && memoryAvailable.value) {
		stages.push(t('synaplan_integration', 'Recalling your memories…'))
	}
	stages.push(t('synaplan_integration', 'Generating the answer…'))

	let i = 0
	loadingText.value = stages[0]
	stageTimer = setInterval(() => {
		i = Math.min(i + 1, stages.length - 1)
		loadingText.value = stages[i]
		if (i === stages.length - 1 && stageTimer) {
			clearInterval(stageTimer)
			stageTimer = null
		}
	}, 1300)
}

/**
 * Stop the staged-progress cycler and reset the label.
 */
function stopThinkingStages() {
	if (stageTimer) {
		clearInterval(stageTimer)
		stageTimer = null
	}
	loadingText.value = t('synaplan_integration', 'Thinking...')
}

/**
 * Load runtime config: the resolved answer language and whether the memory
 * service is available, so the UI can show an accurate status and only offer
 * the "use my memories" toggle when it will actually work.
 */
async function loadClientConfig() {
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/client-config`)
		if (data.success) {
			appVersion.value = data.version || ''
			languageName.value = data.languageName || ''
			memoryAvailable.value = !!(
				data.memory?.allowed && data.memory?.available
			)
			// When memories are available and admin-enabled, use them by
			// default (the toggle becomes an opt-out) — otherwise the switch
			// would show up "on screen but doing nothing". When unavailable the
			// row is hidden entirely.
			useMemories.value = memoryAvailable.value
		}
	} catch {
		// Non-fatal — chat still works without the status row.
	}
}

/**
 *
 */
async function scrollToBottom() {
	await nextTick()
	if (messagesContainer.value) {
		messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
	}
}

/**
 *
 */
async function sendMessage() {
	const text = inputMessage.value.trim()
	if (!text || loading.value) return

	const { command, prompt } = parseCommand(text)

	if (command === 'pic' && !capabilities.value.image) {
		error.value = t(
			'synaplan_integration',
			'Image generation is not available. No image models are configured.',
		)
		return
	}
	if (command === 'vid' && !capabilities.value.video) {
		error.value = t(
			'synaplan_integration',
			'Video generation is not available. No video models are configured.',
		)
		return
	}

	messages.value.push({ role: 'user', text })
	inputMessage.value = ''
	loading.value = true
	error.value = ''
	await scrollToBottom()

	if (command) {
		await handleMediaGeneration(command, prompt)
	} else {
		await handleChatMessage(prompt)
	}

	loading.value = false
	await scrollToBottom()
}

/**
 *
 * @param command
 * @param prompt
 */
async function handleMediaGeneration(command: 'pic' | 'vid', prompt: string) {
	const type = command === 'pic' ? 'image' : 'video'
	loadingText.value =
		type === 'image'
			? t('synaplan_integration', 'Generating image...')
			: t('synaplan_integration', 'Generating video...')

	try {
		const payload: Record<string, unknown> = { prompt, type }
		if (selectedModel.value) {
			payload.modelId = selectedModel.value.id
		}

		const { data } = await axios.post(
			`${baseUrl}/api/v1/media/generate`,
			payload,
		)

		if (data.success && data.file) {
			const fileUrl = data.file.url as string
			const proxyUrl = `${baseUrl}/api/v1/media/proxy?url=${encodeURIComponent(fileUrl)}`
			const filename =
				fileUrl.split('/').pop()
				?? `generated.${type === 'image' ? 'png' : 'mp4'}`

			messages.value.push({
				role: 'assistant',
				text: '',
				media: {
					url: proxyUrl,
					type,
					provider: (data.provider as string) ?? 'unknown',
					model: (data.model as string) ?? 'unknown',
					sourceUrl: fileUrl,
					filename,
					loaded: false,
					failed: false,
					saved: false,
					saving: false,
				},
			})
		} else {
			error.value =
				data.error ?? t('synaplan_integration', 'Media generation failed')
		}
	} catch (err: unknown) {
		error.value = isAxiosError(err)
			? err.response?.data?.error || err.message
			: t('synaplan_integration', 'Unknown error')
	} finally {
		loadingText.value = t('synaplan_integration', 'Thinking...')
	}
}

/**
 *
 * @param text
 */
/**
 * Read Nextcloud's CSRF request token from the document head (the same source
 * the Nextcloud auth helper uses) so plain fetch() calls pass CSRF checks.
 */
function getCsrfToken(): string {
	const head = document.getElementsByTagName('head')[0]
	return head?.getAttribute('data-requesttoken') ?? ''
}

/**
 * Stream a chat answer token-by-token from the SSE proxy, appending deltas to a
 * live assistant message. Falls back to the blocking endpoint on any failure.
 *
 * @param text The user's question
 */
async function handleChatMessage(text: string) {
	startThinkingStages()

	const payload: Record<string, unknown> = { message: text }
	if (selectedGroup.value) {
		payload.groupKey = selectedGroup.value
	}
	if (selectedModel.value?.model) {
		payload.model = selectedModel.value.model
	}
	if (useMemories.value) {
		payload.useMemories = true
	}

	// The assistant message is created on the first token so the "Thinking…"
	// bubble stays visible until content actually starts arriving.
	let assistantIndex = -1
	const appendDelta = (delta: string, model?: string) => {
		if (assistantIndex === -1) {
			assistantIndex = messages.value.push({ role: 'assistant', text: '' }) - 1
			streaming.value = true
			stopThinkingStages()
		}
		messages.value[assistantIndex].text += delta
		if (model && !messages.value[assistantIndex].model) {
			messages.value[assistantIndex].model = model
		}
		scrollToBottom()
	}

	try {
		const response = await fetch(`${baseUrl}/api/v1/chat/stream`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'text/event-stream',
				requesttoken: getCsrfToken(),
			},
			body: JSON.stringify(payload),
		})

		if (!response.ok || !response.body) {
			await handleChatMessageBlocking(payload)
			return
		}

		const reader = response.body.getReader()
		const decoder = new TextDecoder()
		let buffer = ''
		let streamError = ''

		for (;;) {
			const { value, done } = await reader.read()
			if (done) {
				break
			}
			buffer += decoder.decode(value, { stream: true })

			const events = buffer.split('\n\n')
			buffer = events.pop() ?? ''

			for (const event of events) {
				const dataLine = event
					.split('\n')
					.find((line) => line.startsWith('data:'))
				if (!dataLine) {
					continue
				}
				const data = dataLine.slice(5).trim()
				if (data === '' || data === '[DONE]') {
					continue
				}
				try {
					const json = JSON.parse(data)
					if (json.error) {
						streamError = json.error.message || ''
						continue
					}
					const delta = json.choices?.[0]?.delta?.content
					if (typeof delta === 'string' && delta.length > 0) {
						appendDelta(
							delta,
							typeof json.model === 'string' ? json.model : undefined,
						)
					}
				} catch {
					// Ignore keep-alives and partial frames.
				}
			}
		}

		if (assistantIndex === -1) {
			if (streamError) {
				error.value = streamError
			} else {
				await handleChatMessageBlocking(payload)
			}
		} else if (streamError) {
			error.value = streamError
		}
	} catch {
		if (assistantIndex === -1) {
			await handleChatMessageBlocking(payload)
		}
	} finally {
		streaming.value = false
		stopThinkingStages()
	}
}

/**
 * Non-streaming fallback: single blocking request to the JSON chat endpoint.
 *
 * @param payload The chat payload (message, optional groupKey/model)
 */
async function handleChatMessageBlocking(payload: Record<string, unknown>) {
	try {
		const { data } = await axios.post(`${baseUrl}/api/v1/chat`, {
			...payload,
			// The blocking endpoint expects a numeric modelId, not a model name.
			modelId: selectedModel.value?.id,
		})

		if (data.success) {
			messages.value.push({ role: 'assistant', text: data.response })
		} else {
			error.value = data.error || t('synaplan_integration', 'Request failed')
		}
	} catch (err: unknown) {
		error.value = isAxiosError(err)
			? err.response?.data?.error || err.message
			: t('synaplan_integration', 'Unknown error')
	}
}

/**
 *
 * @param messageIdx
 */
async function saveMedia(messageIdx: number) {
	const msg = messages.value[messageIdx]
	if (!msg?.media || msg.media.saved || msg.media.saving) return

	msg.media.saving = true
	error.value = ''

	try {
		const { data } = await axios.post(`${baseUrl}/api/v1/media/save`, {
			mediaUrl: msg.media.sourceUrl,
			filename: msg.media.filename,
		})

		if (data.success) {
			msg.media.saved = true
			msg.media.savedPath = data.path
		} else {
			error.value = data.error ?? t('synaplan_integration', 'Save failed')
		}
	} catch (err: unknown) {
		error.value = isAxiosError(err)
			? err.response?.data?.error || err.message
			: t('synaplan_integration', 'Save failed')
	} finally {
		msg.media.saving = false
	}
}

onMounted(() => {
	loadGroups()
	loadModels()
	loadClientConfig()
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

.knowledge-hint {
	margin: 8px 0 0;
	font-size: 0.82em;
	line-height: 1.4;
	color: var(--color-text-maxcontrast, #767676);
}

/* Memory toggle row */
.memory-row {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 8px 0 0;
}

.memory-hint {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast, #767676);
}

/* Status chips */
.status-row {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding: 8px 0 0;
	flex-shrink: 0;
}

.status-chip {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-size: 0.78em;
	font-weight: 600;
	line-height: 1.2;
	padding: 4px 10px;
	border-radius: 999px;
	/* Neutral surface that stays legible in light and dark themes. */
	background: var(--color-background-hover, var(--color-background-dark, #ededed));
	color: var(--color-main-text, #222);
	border: 1px solid var(--color-border-dark, var(--color-border, #d0d0d0));
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Active capability: solid brand fill with its guaranteed-contrast text token. */
.status-chip.on {
	background: var(--color-primary-element, #0082c9);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}

/* Unavailable capability: clearly muted but still readable (no faint text). */
.status-chip.off {
	background: transparent;
	border-style: dashed;
	border-color: var(--color-border-dark, var(--color-border, #d0d0d0));
	color: var(--color-text-maxcontrast, #767676);
}

.chip-icon {
	font-size: 1.05em;
	line-height: 1;
}

/* Per-answer model caption */
.message-model {
	display: block;
	margin-top: 6px;
	font-size: 0.72em;
	opacity: 0.7;
	font-variant-numeric: tabular-nums;
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
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	color: var(--color-text-maxcontrast, #767676);
	font-size: 1.05em;
	text-align: center;
}

.command-hints {
	margin-top: 8px;
	font-size: 0.9em;
	opacity: 0.8;
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

/* Rendered markdown (assistant messages) — trim default block margins so the
   content sits flush inside the chat bubble, and keep code/quotes readable. */
.markdown-body {
	white-space: normal;
}

.markdown-body :deep(> *:first-child) {
	margin-top: 0;
}

.markdown-body :deep(> *:last-child) {
	margin-bottom: 0;
}

.markdown-body :deep(p) {
	margin: 0 0 6px;
}

.markdown-body :deep(ul),
.markdown-body :deep(ol) {
	margin: 4px 0 6px;
	padding-left: 18px;
}

.markdown-body :deep(li) {
	margin: 1px 0;
}

/* Markdown "loose" lists wrap each item's text in a <p>; strip that margin so
   list items don't get a big gap between them. */
.markdown-body :deep(li > p) {
	margin: 0;
}

.markdown-body :deep(li + li) {
	margin-top: 2px;
}

.markdown-body :deep(h1),
.markdown-body :deep(h2),
.markdown-body :deep(h3) {
	margin: 12px 0 6px;
	line-height: 1.3;
}

.markdown-body :deep(pre),
.markdown-body :deep(code) {
	background: var(--color-background-darker, rgba(0, 0, 0, 0.06));
	border-radius: 4px;
	font-family: var(--font-face-monospace, monospace);
}

.markdown-body :deep(pre) {
	padding: 10px 12px;
	overflow-x: auto;
}

.markdown-body :deep(code) {
	padding: 1px 5px;
}

.markdown-body :deep(pre code) {
	padding: 0;
	background: none;
}

.markdown-body :deep(a) {
	color: var(--color-primary-element, #0082c9);
}

.loading-dots {
	opacity: 0.7;
	font-style: italic;
}

/* Media content */
.media-content {
	margin-top: 8px;
}

.generated-image {
	max-width: 100%;
	max-height: 400px;
	border-radius: 8px;
	display: block;
}

.generated-video {
	max-width: 100%;
	max-height: 400px;
	border-radius: 8px;
	display: block;
}

.media-failed {
	margin-top: 8px;
	font-size: 0.85em;
	color: var(--color-error, #c0392b);
}

.media-meta {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 8px;
	flex-wrap: wrap;
}

.media-provider {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast, #767676);
}

.save-success {
	font-size: 0.85em;
	color: var(--color-success, #46ba61);
	font-weight: 500;
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

/* Help trigger */
.help-trigger-wrapper {
	position: relative;
	flex-shrink: 0;
}

.help-trigger {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border-radius: 50%;
	border: 1px solid var(--color-border-dark, #ccc);
	background: var(--color-background-dark, #ededed);
	color: var(--color-text-maxcontrast, #767676);
	font-weight: 700;
	font-size: 14px;
	cursor: pointer;
	transition:
		background 0.15s,
		color 0.15s;
}

.help-trigger:hover {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border-color: var(--color-primary-element, #0082c9);
}

.help-popover {
	position: absolute;
	bottom: calc(100% + 10px);
	left: 0;
	min-width: 260px;
	padding: 12px 16px;
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: 10px;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.14);
	z-index: 100;
}

.help-popover::after {
	content: '';
	position: absolute;
	top: 100%;
	left: 14px;
	border: 6px solid transparent;
	border-top-color: var(--color-main-background, #fff);
}

.help-popover::before {
	content: '';
	position: absolute;
	top: 100%;
	left: 13px;
	border: 7px solid transparent;
	border-top-color: var(--color-border, #e0e0e0);
}

.help-title {
	margin: 0 0 8px;
	font-weight: 700;
	font-size: 0.9em;
	color: var(--color-main-text, #222);
}

.help-command {
	margin: 4px 0;
	font-size: 0.85em;
	color: var(--color-main-text, #222);
}

.help-command code {
	background: var(--color-background-dark, #ededed);
	padding: 2px 6px;
	border-radius: 4px;
	font-size: 0.9em;
	font-weight: 600;
}

.help-hint {
	margin: 6px 0 0;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast, #767676);
}
</style>
