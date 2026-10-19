<template>
	<NcDialog
		:open="opened"
		:name="dialogTitle"
		size="normal"
		@update:open="onClose">
		<div class="synaplan-chat-modal">
			<AiConsentGate @blocked-change="aiBlocked = $event" />
			<!-- Messages -->
			<div ref="messagesContainer" class="messages">
				<div
					v-for="(msg, idx) in messages"
					:key="idx"
					:class="['message', msg.role]">
					<NcRichText
						v-if="msg.role === 'assistant'"
						class="message-content markdown-body"
						:text="msg.text"
						:use-markdown="true"
						:use-extended-markdown="true" />
					<div v-else class="message-content" v-text="msg.text" />
				</div>
				<div v-if="loading" class="message assistant">
					<div class="message-content loading-dots">
						{{ t('synaplan_integration', 'Thinking...') }}
					</div>
				</div>
			</div>

			<!-- Error -->
			<FailureNote :failure="failure" />

			<!-- Input -->
			<div class="chat-input">
				<NcTextField
					v-model="inputMessage"
					:placeholder="
						t(
							'synaplan_integration',
							'Ask a question about this file...',
						)
					"
					:disabled="loading || aiBlocked"
					@keydown.enter="sendMessage" />
				<NcButton
					variant="primary"
					:disabled="loading || aiBlocked || !inputMessage.trim()"
					@click="sendMessage">
					{{ t('synaplan_integration', 'Send') }}
				</NcButton>
			</div>

			<!-- Deep link -->
			<div v-if="deepLink" class="deep-link">
				<a :href="deepLink" target="_blank" rel="noopener">
					{{ t('synaplan_integration', 'Open Synaplan') }} ↗
				</a>
			</div>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onClose">
				{{ t('synaplan_integration', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcRichText from '@nextcloud/vue/components/NcRichText'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AiConsentGate from './AiConsentGate.vue'
import FailureNote from './FailureNote.vue'
import { describeFailure, failureFromError, type Failure } from '../failures'

interface ChatMessage {
	role: 'user' | 'assistant'
	text: string
}

const props = defineProps<{
	fileId: number
	fileName: string
}>()

const emit = defineEmits<{
	close: [value: boolean | null]
}>()

const opened = ref(true)
const aiBlocked = ref(false)
const loading = ref(false)
const failure = ref<Failure | null>(null)
const inputMessage = ref('')
const messages = ref<ChatMessage[]>([])
const deepLink = ref('')
const messagesContainer = ref<HTMLElement | null>(null)

const dialogTitle = computed(() =>
	t('synaplan_integration', 'Chat: {fileName}', { fileName: props.fileName }),
)

const baseUrl = generateUrl('/apps/synaplan_integration')

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
 * Send a question to the Synaplan API.
 */
async function sendMessage() {
	const text = inputMessage.value.trim()
	if (!text || loading.value) return

	messages.value.push({ role: 'user', text })
	inputMessage.value = ''
	loading.value = true
	failure.value = null
	await scrollToBottom()

	try {
		const { data } = await axios.post(`${baseUrl}/api/v1/chat`, {
			message: text,
			fileId: props.fileId,
		})

		if (data.success) {
			messages.value.push({ role: 'assistant', text: data.response })
			if (data.deepLink) {
				deepLink.value = data.deepLink
			}
		} else {
			failure.value = describeFailure(data.error, data.message)
		}
	} catch (err: unknown) {
		failure.value = failureFromError(err)
	} finally {
		loading.value = false
		await scrollToBottom()
	}
}

/**
 * Close the dialog.
 */
function onClose() {
	opened.value = false
	emit('close', messages.value.length > 0 ? true : null)
}
</script>

<style scoped>
.synaplan-chat-modal {
	display: flex;
	flex-direction: column;
	min-height: 300px;
	max-height: 500px;
}

.messages {
	flex: 1;
	overflow-y: auto;
	padding: 8px 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-height: 0;
}

.message {
	max-width: 85%;
	padding: 10px 14px;
	border-radius: 12px;
	line-height: 1.5;
}

.message.user {
	align-self: flex-end;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.message.assistant {
	align-self: flex-start;
	background: var(--color-background-dark);
}

.message-content {
	word-break: break-word;
}

.message.user .message-content {
	white-space: pre-wrap;
}

/* Rendered markdown — the files script also sets pre-wrap on .message-content. */
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
	font-size: 1em;
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
	color: var(--color-primary-element);
}

.loading-dots {
	opacity: 0.7;
	font-style: italic;
}

.chat-input {
	display: flex;
	gap: 10px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
	margin-top: 8px;
	align-items: center;
}

.chat-input :deep(.input-field) {
	flex: 1;
	min-width: 0;
}

.chat-input :deep(input) {
	width: 100%;
}

.deep-link {
	margin-top: 8px;
	text-align: center;
	font-size: 0.85em;
}

.deep-link a {
	color: var(--color-primary-element);
}
</style>
