<template>
	<div
		v-if="watches.length > 0"
		class="sp-watched-card"
		data-testid="synaplan-watched-folders">
		<div class="sp-watched-head">
			<strong>{{ t('synaplan_integration', 'Watched folders') }}</strong>
		</div>
		<ul class="sp-watched-list">
			<li
				v-for="item in watches"
				:key="item.id"
				class="sp-watched-row"
				data-testid="synaplan-watched-row">
				<div class="sp-watched-meta">
					<span class="sp-watched-name">{{ item.name }}</span>
					<span class="sp-watched-sentence">
						{{
							item.policyOff
								? policyStopped(item.policyOff)
								: item.sentence
						}}
					</span>
					<span v-if="item.lastRunAt" class="sp-watched-when">
						{{
							t('synaplan_integration', 'Last activity {time}', {
								time: formatted(item.lastRunAt),
							})
						}}
					</span>
				</div>
				<div class="sp-watched-actions">
					<button
						v-if="controlsFor(item.state, item.policyOff).pause"
						type="button"
						class="sp-watched-action"
						:disabled="busy === item.id"
						data-testid="synaplan-watched-pause"
						@click="change(item, 'paused')">
						{{ t('synaplan_integration', 'Pause') }}
					</button>
					<button
						v-if="controlsFor(item.state, item.policyOff).resume"
						type="button"
						class="sp-watched-action"
						:disabled="busy === item.id"
						data-testid="synaplan-watched-resume"
						@click="change(item, 'active')">
						{{ t('synaplan_integration', 'Resume') }}
					</button>
					<button
						v-if="controlsFor(item.state, item.policyOff).stop"
						type="button"
						class="sp-watched-action sp-watched-stop"
						:disabled="busy === item.id"
						data-testid="synaplan-watched-stop"
						@click="end(item)">
						{{ t('synaplan_integration', 'Stop watching') }}
					</button>
					<a
						v-if="item.openUrl"
						:href="item.openUrl"
						class="sp-watched-action"
						data-testid="synaplan-watched-open">
						{{
							item.kind === 'file'
								? t('synaplan_integration', 'Open file')
								: t('synaplan_integration', 'Open folder')
						}}
					</a>
				</div>
			</li>
		</ul>
	</div>
</template>

<script setup lang="ts">
import type { WatchRow } from '../watchControls'

import { t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import { controlsFor, policyStopped } from '../watchControls'
import { listWatches, resumeWatch, setWatchState, stopWatch } from '../watches'

const watches = ref<WatchRow[]>([])
const busy = ref<number | null>(null)

/**
 * Load the person's watches.
 */
async function load(): Promise<void> {
	try {
		watches.value = await listWatches()
	} catch {
		// Quiet on the app page when not connected yet.
	}
}

/**
 * A Synaplan time in the person's own format.
 *
 * @param seconds Unix time in seconds
 */
function formatted(seconds: number): string {
	return new Date(seconds * 1000).toLocaleString()
}

/**
 * Pause or resume a watch and show it as it is now.
 *
 * @param item The watch
 * @param next The state the owner asked for
 */
async function change(item: WatchRow, next: 'paused' | 'active'): Promise<void> {
	busy.value = item.id
	try {
		const now =
			next === 'active'
				? await resumeWatch(item.id)
				: await setWatchState(item.id, next)
		if (now) {
			watches.value = watches.value.map((row) =>
				row.id === item.id
					? { ...row, state: now.state, sentence: now.sentence }
					: row,
			)
		}
	} finally {
		busy.value = null
	}
}

/**
 * Stop a watch after asking once.
 *
 * @param item The watch
 */
async function end(item: WatchRow): Promise<void> {
	busy.value = item.id
	try {
		if (await stopWatch(item.id, item.name)) {
			watches.value = watches.value.filter((row) => row.id !== item.id)
		}
	} finally {
		busy.value = null
	}
}

onMounted(() => {
	load()
})
</script>

<style scoped>
.sp-watched-card {
	width: 100%;
	max-width: 800px;
	margin: 0 auto 24px;
	padding: 16px 20px;
	border-radius: 14px;
	background: var(--color-background-dark, rgba(0, 0, 0, 0.35));
	border: 1px solid var(--color-border, rgba(255, 255, 255, 0.12));
	box-sizing: border-box;
}

.sp-watched-head {
	margin-bottom: 10px;
}

.sp-watched-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.sp-watched-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	font-size: 0.9em;
}

.sp-watched-meta {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	/* Without a basis the actions never wrap and squeeze the text to a word. */
	flex: 1 1 16rem;
}

.sp-watched-name {
	font-weight: 600;
	color: var(--color-main-text, #222);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sp-watched-sentence,
.sp-watched-when {
	color: var(--color-text-maxcontrast, #767676);
}

.sp-watched-when {
	font-size: 0.85em;
}

.sp-watched-actions {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 12px;
}

.sp-watched-action {
	background: transparent;
	border: none;
	padding: 0;
	color: var(--color-primary-element, #0082c9);
	font-size: 0.9em;
	font-weight: 600;
	text-decoration: none;
	cursor: pointer;
}

.sp-watched-stop {
	color: var(--color-error, #d93025);
}
</style>
