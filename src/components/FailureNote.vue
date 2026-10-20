<template>
	<NcNoteCard v-if="failure" type="error">
		<p>{{ failure.text }}</p>
		<a
			v-if="failure.settingsUrl"
			class="failure-link"
			:href="failure.settingsUrl">
			{{ t('synaplan_integration', 'Open Synaplan settings') }}
		</a>
	</NcNoteCard>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import type { Failure } from '../failures'

defineProps<{
	failure: Failure | null
}>()
</script>

<style scoped>
/* The note card's own text colour keeps the link readable on its tinted background in both themes. */
.failure-link {
	color: inherit;
	font-weight: bold;
	text-decoration: underline;
}
</style>
