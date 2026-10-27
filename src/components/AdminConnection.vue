<template>
	<div>
		<div class="sp-field">
			<strong>{{
				t('synaplan_integration', 'How do people here use Synaplan?')
			}}</strong>
			<div class="sp-env-switch sp-modes">
				<div class="sp-mode">
					<NcCheckboxRadioSwitch
						:model-value="mode"
						value="sso"
						name="sp-mode"
						type="radio"
						disabled>
						{{ t('synaplan_integration', 'Organisation login') }}
					</NcCheckboxRadioSwitch>
					<p class="sp-hint sp-mode-hint">
						{{
							t(
								'synaplan_integration',
								'People sign in once with your identity provider.',
							)
						}}
						{{
							t(
								'synaplan_integration',
								'Needs the Nextcloud app user_oidc.',
							)
						}}
					</p>
				</div>
				<div class="sp-mode">
					<NcCheckboxRadioSwitch
						:model-value="mode"
						value="link"
						name="sp-mode"
						type="radio"
						:disabled="saving"
						@update:model-value="emit('update:mode', 'link')">
						{{
							t(
								'synaplan_integration',
								'Connect your Synaplan account',
							)
						}}
					</NcCheckboxRadioSwitch>
					<p class="sp-hint sp-mode-hint">
						{{
							t(
								'synaplan_integration',
								'Each person connects an account they already have.',
							)
						}}
					</p>
				</div>
				<div class="sp-mode">
					<NcCheckboxRadioSwitch
						:model-value="mode"
						value="provision"
						name="sp-mode"
						type="radio"
						:disabled="saving"
						@update:model-value="emit('update:mode', 'provision')">
						{{
							t(
								'synaplan_integration',
								'Accounts created by the administrator',
							)
						}}
					</NcCheckboxRadioSwitch>
					<p class="sp-hint sp-mode-hint">
						{{
							t(
								'synaplan_integration',
								'This server creates a Synaplan account per person.',
							)
						}}
					</p>
				</div>
				<div class="sp-mode">
					<NcCheckboxRadioSwitch
						:model-value="mode"
						value="shared"
						name="sp-mode"
						type="radio"
						:disabled="saving"
						@update:model-value="emit('update:mode', 'shared')">
						{{ t('synaplan_integration', 'One shared account') }}
					</NcCheckboxRadioSwitch>
					<p class="sp-hint sp-mode-hint">
						{{
							t(
								'synaplan_integration',
								'Everyone acts as one Synaplan account and sees its files.',
							)
						}}
						{{
							t(
								'synaplan_integration',
								'Not for teams with private documents.',
							)
						}}
					</p>
				</div>
			</div>
		</div>

		<template v-if="mode === 'link'">
			<div :style="{ height: '12px' }" />
			<NcCheckboxRadioSwitch
				:model-value="linkAutoProvision"
				type="switch"
				:disabled="saving || !hasAdminKey"
				@update:model-value="emit('update:linkAutoProvision', $event)">
				{{
					t(
						'synaplan_integration',
						'Offer to create an account for users who have none',
					)
				}}
			</NcCheckboxRadioSwitch>
			<p class="sp-hint sp-switch-hint">
				{{
					t(
						'synaplan_integration',
						'Needs the admin API key. Users who already have a Synaplan account still connect it.',
					)
				}}
			</p>
		</template>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

defineProps<{
	mode: 'shared' | 'provision' | 'link'
	linkAutoProvision: boolean
	hasAdminKey: boolean
	saving: boolean
}>()

const emit = defineEmits<{
	'update:mode': [mode: 'shared' | 'provision' | 'link']
	'update:linkAutoProvision': [value: boolean]
}>()
</script>

<style scoped>
.sp-field {
	margin-bottom: 24px;
}

.sp-field strong {
	display: block;
	margin-bottom: 4px;
}

.sp-modes {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-top: 8px;
}

.sp-mode-hint {
	margin: 0 0 0 28px;
}

.sp-hint {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #767676);
	margin: 0 0 8px;
}

.sp-switch-hint {
	margin: 4px 0 0 44px;
	max-width: 560px;
}
</style>
