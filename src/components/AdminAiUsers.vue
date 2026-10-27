<template>
	<NcSettingsSection :name="t('synaplan_integration', 'AI Users')">
		<p class="sp-hint">
			{{
				t(
					'synaplan_integration',
					'Nextcloud users who have activated AI. Each has their own Synaplan account, files and usage.',
				)
			}}
		</p>
		<div class="sp-aiusers-toolbar">
			<NcTextField
				:value="search"
				:label="t('synaplan_integration', 'Search name or email')"
				:label-visible="false"
				:placeholder="t('synaplan_integration', 'Search name or email')"
				class="sp-aiusers-search"
				@update:value="search = $event" />
			<span
				role="button"
				tabindex="0"
				:style="secondaryBtnStyle"
				@click="!loading && load()"
				@keydown.enter="!loading && load()">
				{{
					loading
						? t('synaplan_integration', 'Loading...')
						: t('synaplan_integration', 'Refresh')
				}}
			</span>
			<span class="sp-hint">{{
				t('synaplan_integration', '{count} activated', {
					count: users.length,
				})
			}}</span>
		</div>
		<p v-if="users.length === 0 && !loading" class="sp-hint">
			{{ t('synaplan_integration', 'No users have activated AI yet.') }}
		</p>
		<p v-else-if="filtered.length === 0" class="sp-hint">
			{{ t('synaplan_integration', 'No users match your search.') }}
		</p>
		<div v-else class="sp-aiusers-list">
			<div v-for="u in pageRows" :key="u.uid" class="sp-aiuser">
				<div class="sp-aiuser-row">
					<div class="sp-aiuser-main">
						<span class="sp-aiuser-name">{{ u.displayName }}</span>
						<span class="sp-uid">({{ u.uid }})</span>
					</div>
					<div class="sp-aiuser-meta">
						<span v-if="u.kind" class="sp-kind-badge">
							{{
								u.kind === 'linked'
									? t('synaplan_integration', 'Connected')
									: t('synaplan_integration', 'Created for them')
							}}
						</span>
						<span v-if="u.email">{{ u.email }}</span>
						<span
							>{{ t('synaplan_integration', 'Synaplan ID') }}:
							{{ u.synaplanUserId ?? '—' }}</span
						>
						<span>{{
							u.hasKey
								? t('synaplan_integration', 'Key ✓')
								: t('synaplan_integration', 'No key')
						}}</span>
						<span>{{ formatConsentDate(u.consentAt) }}</span>
					</div>
					<div class="sp-aiusers-actions">
						<span
							role="button"
							tabindex="0"
							class="sp-aiusers-link"
							@click="openDetails(u)"
							@keydown.enter="openDetails(u)">
							{{ t('synaplan_integration', 'Details') }}
						</span>
						<span
							role="button"
							tabindex="0"
							class="sp-aiusers-link sp-aiusers-danger"
							@click="deactivate(u)"
							@keydown.enter="deactivate(u)">
							{{ t('synaplan_integration', 'Deactivate') }}
						</span>
					</div>
				</div>
			</div>
		</div>
		<div v-if="pages > 1" class="sp-aiusers-pagination">
			<span
				role="button"
				tabindex="0"
				class="sp-page-btn"
				:class="{ 'sp-page-disabled': page <= 1 }"
				@click="page > 1 && page--"
				@keydown.enter="page > 1 && page--">
				‹ {{ t('synaplan_integration', 'Previous') }}
			</span>
			<span class="sp-hint">{{ page }} / {{ pages }}</span>
			<span
				role="button"
				tabindex="0"
				class="sp-page-btn"
				:class="{ 'sp-page-disabled': page >= pages }"
				@click="page < pages && page++"
				@keydown.enter="page < pages && page++">
				{{ t('synaplan_integration', 'Next') }} ›
			</span>
		</div>
		<NcDialog
			:open="detail !== null"
			:name="detailTitle"
			size="normal"
			@update:open="onDetailOpen">
			<div v-if="detail" class="sp-user-detail">
				<dl class="sp-detail-grid">
					<dt>{{ t('synaplan_integration', 'Name') }}</dt>
					<dd>{{ detail.displayName }}</dd>
					<dt>{{ t('synaplan_integration', 'Username') }}</dt>
					<dd>{{ detail.uid }}</dd>
					<dt>{{ t('synaplan_integration', 'Email') }}</dt>
					<dd>{{ detail.email || '—' }}</dd>
					<dt>{{ t('synaplan_integration', 'Synaplan account ID') }}</dt>
					<dd>{{ detail.synaplanUserId ?? '—' }}</dd>
					<dt>{{ t('synaplan_integration', 'API key') }}</dt>
					<dd>
						{{
							detail.hasKey
								? t('synaplan_integration', 'Issued')
								: t('synaplan_integration', 'None')
						}}
					</dd>
					<dt>{{ t('synaplan_integration', 'Activated') }}</dt>
					<dd>{{ formatConsentDate(detail.consentAt) }}</dd>
				</dl>
				<h4 class="sp-detail-heading">
					{{ t('synaplan_integration', 'Usage') }}
				</h4>
				<span v-if="usageLoading[detail.uid]" class="sp-hint">{{
					t('synaplan_integration', 'Loading usage...')
				}}</span>
				<ul v-else-if="detailEntries.length" class="sp-usage-list">
					<li v-for="entry in detailEntries" :key="entry.key">
						<strong>{{ entry.key }}:</strong> {{ entry.value }}
					</li>
				</ul>
				<span v-else class="sp-hint">{{
					t('synaplan_integration', 'No usage data available.')
				}}</span>
			</div>
		</NcDialog>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'

interface AiUser {
	uid: string
	displayName: string
	email: string
	synaplanUserId: number | null
	hasKey: boolean
	consentAt: string
	kind?: 'linked' | 'provisioned' | null
}

const emit = defineEmits<{
	notify: [text: string, type?: 'success' | 'error']
}>()

const PER_PAGE = 20
const baseUrl = generateUrl('/apps/synaplan_integration')

const users = ref<AiUser[]>([])
const loading = ref(false)
const search = ref('')
const page = ref(1)
const usageLoading = ref<Record<string, boolean>>({})
const usageData = ref<Record<string, unknown>>({})
const detail = ref<AiUser | null>(null)

const filtered = computed(() => {
	const q = search.value.trim().toLowerCase()
	if (!q) {
		return users.value
	}
	return users.value.filter(
		(u) =>
			u.displayName.toLowerCase().includes(q)
			|| u.uid.toLowerCase().includes(q)
			|| (u.email || '').toLowerCase().includes(q),
	)
})
const pages = computed(() =>
	Math.max(1, Math.ceil(filtered.value.length / PER_PAGE)),
)
const pageRows = computed(() => {
	const start = (page.value - 1) * PER_PAGE
	return filtered.value.slice(start, start + PER_PAGE)
})

watch(search, () => {
	page.value = 1
})

const secondaryBtnStyle = computed((): Record<string, string> => ({
	display: 'inline-block',
	padding: '10px 28px',
	borderRadius: '22px',
	fontSize: '0.95em',
	fontWeight: '600',
	cursor: 'pointer',
	lineHeight: '1.2',
	userSelect: 'none',
	textAlign: 'center',
	background: 'transparent',
	color: 'var(--color-main-text, #222)',
	border: '2px solid var(--color-border-dark, rgba(255, 255, 255, 0.25))',
	opacity: loading.value ? '0.5' : '1',
}))

const detailTitle = computed(() =>
	detail.value
		? t('synaplan_integration', 'AI user: {name}', {
				name: detail.value.displayName,
			})
		: '',
)

const detailEntries = computed(() => (detail.value ? entries(detail.value.uid) : []))

onMounted(() => {
	load()
})

/**
 * Load the people who have activated AI.
 */
async function load() {
	loading.value = true
	try {
		const { data } = await axios.get(`${baseUrl}/api/v1/admin/ai-users`)
		if (data.success) {
			users.value = data.users || []
		}
	} catch {
		emit(
			'notify',
			t('synaplan_integration', 'Failed to load AI users.'),
			'error',
		)
	} finally {
		loading.value = false
	}
}

/**
 * Open one person's details and load usage the first time.
 *
 * @param {AiUser} user The row
 */
async function openDetails(user: AiUser) {
	detail.value = user
	if (usageData.value[user.uid] !== undefined) {
		return
	}
	usageLoading.value = { ...usageLoading.value, [user.uid]: true }
	try {
		const { data } = await axios.get(
			`${baseUrl}/api/v1/admin/ai-users/${encodeURIComponent(user.uid)}/usage`,
		)
		usageData.value = { ...usageData.value, [user.uid]: data }
	} catch {
		usageData.value = { ...usageData.value, [user.uid]: {} }
	} finally {
		usageLoading.value = { ...usageLoading.value, [user.uid]: false }
	}
}

/**
 * Close the details when the dialog is dismissed.
 *
 * @param {boolean} open New open state
 */
function onDetailOpen(open: boolean) {
	if (!open) {
		detail.value = null
	}
}

/**
 * Flatten a person's usage payload into rows.
 *
 * @param {string} uid Nextcloud user id
 */
function entries(uid: string): { key: string; value: string }[] {
	const data = usageData.value[uid] as Record<string, unknown> | undefined
	const usage = (data?.usage ?? data) as Record<string, unknown> | undefined
	if (!usage || typeof usage !== 'object') {
		return []
	}
	return Object.entries(usage)
		.filter(([, value]) => value !== null && typeof value !== 'object')
		.map(([key, value]) => ({ key, value: String(value) }))
}

/**
 * Deactivate AI for one person.
 *
 * @param {AiUser} user The row
 */
async function deactivate(user: AiUser) {
	try {
		await axios.post(
			`${baseUrl}/api/v1/admin/ai-users/${encodeURIComponent(user.uid)}/deactivate`,
		)
		emit(
			'notify',
			t('synaplan_integration', 'Deactivated AI for {name}.', {
				name: user.displayName,
			}),
		)
		await load()
	} catch {
		emit(
			'notify',
			t('synaplan_integration', 'Failed to deactivate user.'),
			'error',
		)
	}
}

/**
 * Human-readable activation date.
 *
 * @param {string} iso ISO timestamp
 */
function formatConsentDate(iso: string): string {
	if (!iso) {
		return '—'
	}
	const date = new Date(iso)
	if (Number.isNaN(date.getTime())) {
		return iso
	}
	return date.toLocaleString()
}
</script>

<style scoped>
.sp-hint {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #767676);
	margin: 0 0 8px;
}

.sp-aiusers-toolbar {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 12px;
	margin: 8px 0 12px;
}

.sp-aiusers-search {
	flex: 1 1 240px;
	max-width: 360px;
}

.sp-aiusers-pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	margin-top: 12px;
}

.sp-page-btn {
	cursor: pointer;
	color: var(--color-primary-element, #0082c9);
	font-weight: 500;
}

.sp-page-disabled {
	color: var(--color-text-maxcontrast, #8a8a8a);
	cursor: default;
	opacity: 0.5;
}

.sp-aiusers-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	font-size: 0.9em;
}

.sp-aiuser {
	border: 1px solid var(--color-border, rgba(127, 127, 127, 0.2));
	border-radius: var(--border-radius-large, 8px);
	padding: 8px 12px;
}

.sp-aiuser-row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 20px;
}

.sp-aiuser-main {
	flex: 1 1 180px;
}

.sp-aiuser-name {
	font-weight: 600;
	color: var(--color-main-text, #222);
}

.sp-aiuser-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 16px;
	color: var(--color-text-maxcontrast, #6b6b6b);
}

.sp-uid {
	color: var(--color-text-maxcontrast, #6b6b6b);
	font-size: 0.85em;
}

.sp-aiusers-actions {
	display: flex;
	gap: 12px;
	margin-left: auto;
}

.sp-aiusers-link {
	cursor: pointer;
	color: var(--color-primary-element, #0082c9);
	font-weight: 500;
}

.sp-aiusers-danger {
	color: var(--color-error, #e9322d);
}

.sp-user-detail {
	padding: 8px 4px;
}

.sp-detail-grid {
	display: grid;
	grid-template-columns: minmax(120px, auto) 1fr;
	gap: 6px 16px;
	margin: 0 0 16px;
}

.sp-detail-grid dt {
	color: var(--color-text-maxcontrast, #767676);
	font-weight: 600;
}

.sp-detail-grid dd {
	margin: 0;
	color: var(--color-main-text, #222);
	word-break: break-word;
}

.sp-detail-heading {
	margin: 8px 0;
	font-weight: 600;
}

.sp-usage-list {
	margin: 0;
	padding: 0 0 0 16px;
	columns: 2;
}

.sp-kind-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 0.8em;
	font-weight: 600;
	background: var(--color-background-dark, rgba(127, 127, 127, 0.15));
	color: var(--color-main-text, #222);
}
</style>
