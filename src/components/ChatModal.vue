<template>
	<NcDialog
		:open="opened"
		:name="dialogTitle"
		size="normal"
		@update:open="onClose">
		<div class="synaplan-chat-modal">
			<!-- Messages -->
			<div ref="messagesContainer" class="messages">
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
					:placeholder="
						t(
							'synaplan_integration',
							'Ask a question about this file...',
						)
					"
					:disabled="loading"
					@keydown.enter="sendMessage" />
				<NcButton
					type="primary"
					:disabled="loading || !inputMessage.trim()"
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
			<NcButton type="tertiary" @click="onClose">
				{{ t('synaplan_integration', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import axios, { isAxiosError } from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

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
const loading = ref(false)
const error = ref('')
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
	error.value = ''
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
			error.value =
				data.error || t('synaplan_integration', 'Chat request failed')
		}
	} catch (err: unknown) {
		error.value = isAxiosError(err)
			? err.response?.data?.error || err.message
			: t('synaplan_integration', 'Unknown error')
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
