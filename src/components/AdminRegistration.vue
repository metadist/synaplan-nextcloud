<template>
	<NcSettingsSection
		v-if="mode === 'link' || mode === 'provision'"
		:name="t('synaplan_integration', 'This Nextcloud')">
		<p class="sp-hint">
			{{
				mode === 'provision'
					? t(
							'synaplan_integration',
							'Register this Nextcloud before creating accounts, so each person belongs to this server. You do this once.',
						)
					: t(
							'synaplan_integration',
							'Register this Nextcloud so people can connect their Synaplan account. You do this once.',
						)
			}}
		</p>
		<p v-if="linkRegistered" class="sp-status">
			{{ instanceStatusLabel }}
		</p>
		<p v-else class="sp-status">
			{{ t('synaplan_integration', 'Not registered yet.') }}
		</p>
		<div :style="{ height: '16px' }" />
		<div class="sp-field">
			<strong>{{
				t(
					'synaplan_integration',
					'Address Synaplan uses to reach this Nextcloud',
				)
			}}</strong>
			<p class="sp-hint">
				{{
					t(
						'synaplan_integration',
						'Leave empty on a normal server. On the local Docker install, Synaplan reaches Nextcloud at http://nextcloud.',
					)
				}}
			</p>
			<div class="sp-input">
				<NcTextField
					id="synaplan-delivery-url"
					:model-value="deliveryUrl"
					:label="
						t(
							'synaplan_integration',
							'Address Synaplan uses to reach this Nextcloud',
						)
					"
					:label-visible="false"
					:disabled="saving"
					:style="{ width: '100%' }"
					@update:model-value="
						emit('update:deliveryUrl', String($event))
					" />
			</div>
		</div>
		<div :style="{ display: 'flex', gap: '16px', margin: '12px 0' }">
			<span
				v-if="!linkRegistered"
				role="button"
				tabindex="0"
				:style="primaryBtnStyle"
				@click="!registering && emit('register')"
				@keydown.enter="!registering && emit('register')"
				@keydown.space.prevent="!registering && emit('register')">
				{{
					registering
						? t('synaplan_integration', 'Registering…')
						: t('synaplan_integration', 'Register this instance')
				}}
			</span>
			<span
				v-else
				role="button"
				tabindex="0"
				:style="secondaryBtnStyle"
				@click="!registering && emit('forget')"
				@keydown.enter="!registering && emit('forget')"
				@keydown.space.prevent="!registering && emit('forget')">
				{{ t('synaplan_integration', 'Forget registration') }}
			</span>
		</div>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const props = defineProps<{
	mode: 'shared' | 'provision' | 'link'
	deliveryUrl: string
	saving: boolean
	linkRegistered: boolean
	linkInstanceId: string
	linkInstance: { status?: string; host?: string; error?: string } | null
	registering: boolean
	primaryBtnStyle: Record<string, string>
	secondaryBtnStyle: Record<string, string>
}>()

const emit = defineEmits<{
	'update:deliveryUrl': [value: string]
	register: []
	forget: []
}>()

const instanceStatusLabel = computed(() => {
	const host = props.linkInstance?.host || ''
	if (props.linkInstance?.status === 'pending') {
		return host
			? t(
					'synaplan_integration',
					'Waiting for approval by the Synaplan administrator ({host}).',
					{ host },
				)
			: t(
					'synaplan_integration',
					'Waiting for approval by the Synaplan administrator.',
				)
	}
	if (props.linkInstance?.status === 'active') {
		return host
			? t('synaplan_integration', 'Registered and ready ({host}).', { host })
			: t('synaplan_integration', 'Registered and ready.')
	}
	if (props.linkInstanceId) {
		return t('synaplan_integration', 'Registered. Checking status…')
	}
	return t('synaplan_integration', 'Not registered yet.')
})
</script>

<style scoped>
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

.sp-input {
	width: 500px;
	max-width: 100%;
	margin: 4px 0;
}
</style>
