<script setup>
import { computed, ref, watch } from 'vue';

import TypeBadge from '@/Components/Dashboards/TypeBadge.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { SmallButtonClass } from '@/Components/Insights/classes';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { addTile, listAll } from '@/lib/dashboardsApi';
import { pickable } from '@/lib/filterInsights';

// The picker of "Add tile": the saved insights that are not on the dashboard yet, searchable. Each row has its own Add button
// (named after the insight); an added insight leaves the list, so the dialog can stay open to add several. The dialog loads the
// list when it opens (a project has at most 200 saved insights). A saved insight that needs review can be added too: its tile
// says so and links to the editor.
const props = defineProps({
    open: { type: Boolean, default: false },
    insightsUrl: { type: String, required: true },
    dashboardsUrl: { type: String, required: true },
    dashboardId: { type: String, required: true },
    usedIds: { type: Array, required: true },
    full: { type: Boolean, default: false },
    maxTiles: { type: Number, required: true },
});

const emit = defineEmits(['close', 'added']);

const all = ref([]);
const state = ref('loading');
const failure = ref('');
const search = ref('');
const adding = ref(null);
const error = ref('');
const status = ref('');

const rows = computed(() => pickable(all.value, props.usedIds, search.value));

async function load() {
    state.value = 'loading';
    const answer = await listAll(props.insightsUrl);
    if (answer.ok) {
        all.value = answer.data;
        state.value = 'ready';
    } else {
        failure.value = answer.message;
        state.value = 'failed';
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            search.value = '';
            error.value = '';
            status.value = '';
            load();
        }
    },
    { immediate: true },
);

async function add(insight) {
    if (adding.value !== null) {
        return;
    }

    adding.value = insight.id;
    error.value = '';
    const answer = await addTile(props.dashboardsUrl, props.dashboardId, insight.id);
    adding.value = null;

    if (answer.ok) {
        status.value = `Added ${insight.name} to the dashboard.`;
        emit('added', answer.data);
    } else {
        error.value = answer.errors?.limit ?? answer.errors?.saved_insight_id ?? answer.message;
    }
}

const fieldClass = 'block w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-fg';
</script>

<template>
    <ConfirmDialog :open="open" title="Add a tile" @close="emit('close')">
        <template #description>Pick a saved insight to show on this dashboard. {{ full ? `The dashboard has reached its limit of ${maxTiles} tiles.` : '' }}</template>

        <div class="space-y-1.5">
            <label for="tile-search" class="block text-sm font-medium">Search saved insights</label>
            <input id="tile-search" v-model="search" type="search" autocomplete="off" :class="fieldClass" autofocus />
        </div>

        <div class="sr-only" role="status" aria-live="polite" aria-atomic="true">{{ status }}</div>
        <p v-if="error" role="alert" class="rounded-md border border-danger px-3 py-2 text-sm text-danger" data-testid="dialog-error">{{ error }}</p>

        <div class="min-h-24" :aria-busy="state === 'loading' ? 'true' : 'false'">
            <p v-if="state === 'loading'" class="text-sm text-muted">Loading saved insights…</p>
            <div v-else-if="state === 'failed'" role="alert" class="space-y-3">
                <p class="text-danger">{{ failure }}</p>
                <SecondaryButton type="button" @click="load">Try again</SecondaryButton>
            </div>
            <p v-else-if="all.length === 0" class="text-sm text-muted" data-testid="picker-none">There are no saved insights yet. Save one from Trends, Funnel or Retention, then add it here.</p>
            <p v-else-if="rows.length === 0" class="text-sm text-muted" data-testid="picker-empty">{{ search.trim() === '' ? 'Every saved insight is already on this dashboard.' : 'No saved insight matches.' }}</p>
            <ul v-else class="max-h-72 space-y-2 overflow-y-auto" aria-label="Saved insights you can add" data-testid="picker-list">
                <li v-for="insight in rows" :key="insight.id" class="flex items-center justify-between gap-3 rounded-md border border-line px-3 py-2">
                    <span class="min-w-0 space-y-0.5">
                        <span class="block font-medium break-words">{{ insight.name }}</span>
                        <TypeBadge :type="insight.type" />
                    </span>
                    <button type="button" :class="SmallButtonClass" :aria-disabled="full || adding !== null ? 'true' : undefined" :aria-label="`Add ${insight.name}`" @click="!full && add(insight)">Add</button>
                </li>
            </ul>
        </div>

        <div class="flex justify-end">
            <SecondaryButton type="button" @click="emit('close')">Done</SecondaryButton>
        </div>
    </ConfirmDialog>
</template>
