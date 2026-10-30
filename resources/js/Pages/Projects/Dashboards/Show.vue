<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';

import AddTileDialog from '@/Components/Dashboards/AddTileDialog.vue';
import DashboardTile from '@/Components/Dashboards/DashboardTile.vue';
import DeleteDialog from '@/Components/Dashboards/DeleteDialog.vue';
import LabelDialog from '@/Components/Dashboards/LabelDialog.vue';
import { SecondaryButtonClass } from '@/Components/Insights/classes';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useNow } from '@/composables/useNow';
import { deleteDashboard, getDashboard, removeTile, reorderTiles, savedInsightRunUrl, updateDashboard } from '@/lib/dashboardsApi';
import { RunQueue } from '@/lib/runQueue';
import { withSaved } from '@/lib/savedInsightLinks';
import { arrange, move, moveMessage, sameOrder } from '@/lib/tileOrder';

// One dashboard: its tiles in position order, each running its saved insight (see DashboardTile). The page reads the dashboard
// through the JSON API (which also records that it was viewed, so the scheduled refresh keeps it warm) and writes through it:
// rename, delete, add and remove tiles, and the order (Move earlier / Move later send the WHOLE new order; when the server
// answers 422 because the tiles changed elsewhere, the page reloads the dashboard instead of guessing).
//
// At most `limits.concurrent_runs` tiles run at once: every tile asks through one RunQueue, in order, and only once it is in
// view. Refresh all asks every tile again, with `fresh: true`, through the same queue.
const props = defineProps({
    project: { type: Object, required: true },
    dashboard: { type: Object, required: true },
    links: { type: Object, required: true },
    urls: { type: Object, required: true },
    limits: { type: Object, required: true },
});

const now = useNow();
const queue = new RunQueue(props.limits.concurrent_runs);
onBeforeUnmount(() => queue.clear());

const detail = shallowRef(null);
const tiles = ref([]);
const state = ref('loading');
const failure = ref('');
const status = ref('');
const heading = ref(null);
const tileRefs = new Map();

const name = computed(() => detail.value?.name ?? props.dashboard.name);
const ids = computed(() => tiles.value.map((tile) => tile.id));
const full = computed(() => tiles.value.length >= props.limits.max_tiles);
const usedInsightIds = computed(() => tiles.value.map((tile) => tile.saved_insight.id));
const dashboardsApi = props.links.api.dashboards;
const pageUrl = (type) => props.links.pages[type];

const setRef = (id) => (component) => (component ? tileRefs.set(id, component) : tileRefs.delete(id));

async function load(message = '') {
    const answer = await getDashboard(dashboardsApi, props.dashboard.id);
    if (answer.ok) {
        detail.value = answer.data;
        tiles.value = answer.data.tiles;
        state.value = 'ready';
        if (message !== '') {
            status.value = message;
        }
    } else if (answer.kind === 'not_found') {
        state.value = 'missing';
    } else {
        failure.value = answer.message;
        state.value = tiles.value.length > 0 ? 'ready' : 'failed';
        status.value = answer.message;
    }
}

onMounted(() => load());

// ------------------------------------------------------------ the tiles
const runUrl = (tile) => savedInsightRunUrl(props.links.api.saved_insights, tile.saved_insight.id);
const openUrl = (tile) => `${pageUrl(tile.saved_insight.type) ?? ''}?${withSaved('', tile.saved_insight.id)}`;

function refreshAll() {
    for (const tile of tiles.value) {
        tileRefs.get(tile.id)?.refresh();
    }
    status.value = 'Refreshing every tile.';
}

async function onMove(tile, delta) {
    const before = ids.value;
    const after = move(before, tile.id, delta);
    if (sameOrder(before, after)) {
        return;
    }

    tiles.value = arrange(tiles.value, after);
    status.value = moveMessage(tile.saved_insight.name, after, tile.id, delta);
    tileRefs.get(tile.id)?.focusMove(delta);

    const answer = await reorderTiles(dashboardsApi, props.dashboard.id, after);
    if (answer.ok) {
        return;
    }
    if (answer.kind === 'validation') {
        await load('The tiles of this dashboard changed elsewhere. The latest order is shown.');
    } else {
        tiles.value = arrange(tiles.value, before);
        status.value = `${answer.message} The order was not saved.`;
    }
}

async function onRemove(tile) {
    const index = tiles.value.findIndex((item) => item.id === tile.id);
    const answer = await removeTile(dashboardsApi, props.dashboard.id, tile.id);
    if (!answer.ok && answer.kind !== 'not_found') {
        status.value = answer.message;

        return;
    }

    tiles.value = tiles.value.filter((item) => item.id !== tile.id);
    status.value = `Removed ${tile.saved_insight.name} from the dashboard. The saved insight is kept.`;
    await nextTick();
    const next = tiles.value[Math.min(index, tiles.value.length - 1)];
    if (next !== undefined && tileRefs.has(next.id)) {
        tileRefs.get(next.id).focusTitle();
    } else {
        (document.getElementById('add-tile') ?? heading.value)?.focus();
    }
}

const pickerOpen = ref(false);

function added(tile) {
    if (!tiles.value.some((item) => item.id === tile.id)) {
        tiles.value = [...tiles.value, tile];
    }
    detail.value = { ...detail.value, tiles_count: tiles.value.length };
}

// ------------------------------------------------------------ rename and delete
const renameOpen = ref(false);
const rename = (fields) => updateDashboard(dashboardsApi, props.dashboard.id, { name: fields.name, description: fields.description === '' ? null : fields.description });

function renamed(updated) {
    detail.value = { ...detail.value, name: updated.name, description: updated.description };
    status.value = `Renamed the dashboard to ${updated.name}.`;
}

const deleteOpen = ref(false);
const destroy = () => deleteDashboard(dashboardsApi, props.dashboard.id);
const deleted = () => router.visit(pageUrl('dashboards'));
</script>

<template>
    <Head :title="`${name} - ${project.name}`" />

    <div class="space-y-6">
        <header class="space-y-2">
            <p class="text-sm"><Link :href="links.pages.dashboards" class="text-accent hover:text-accent-hover">Dashboards</Link></p>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 space-y-1">
                    <h1 ref="heading" tabindex="-1" class="text-2xl font-semibold tracking-tight break-words focus:outline-none" data-testid="dashboard-name">{{ name }}</h1>
                    <p v-if="detail?.description" class="max-w-prose text-sm text-muted break-words" data-testid="dashboard-description">{{ detail.description }}</p>
                </div>
                <div v-if="state === 'ready'" class="flex flex-wrap items-center gap-2">
                    <PrimaryButton id="add-tile" type="button" aria-haspopup="dialog" :disabled="full" @click="!full && (pickerOpen = true)">Add tile</PrimaryButton>
                    <SecondaryButton type="button" :disabled="tiles.length === 0" @click="tiles.length > 0 && refreshAll()">Refresh all</SecondaryButton>
                    <SecondaryButton type="button" aria-haspopup="dialog" @click="renameOpen = true">Rename</SecondaryButton>
                    <SecondaryButton type="button" aria-haspopup="dialog" @click="deleteOpen = true">Delete dashboard</SecondaryButton>
                </div>
            </div>
            <p v-if="state === 'ready' && full" class="text-sm text-muted" data-testid="limit-note">This dashboard has reached its limit of {{ limits.max_tiles }} tiles. Remove one to add another.</p>
        </header>

        <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-testid="status">{{ status }}</div>

        <section aria-label="Tiles" :aria-busy="state === 'loading' ? 'true' : 'false'">
            <div v-if="state === 'loading'" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" aria-hidden="true" data-testid="state-loading">
                <div v-for="n in 3" :key="n" class="h-[33rem] animate-pulse rounded-md bg-line"></div>
            </div>

            <div v-else-if="state === 'failed'" role="alert" class="space-y-3 rounded-md border border-danger px-4 py-6" data-testid="state-failed">
                <p class="font-medium text-danger">{{ failure }}</p>
                <SecondaryButton type="button" @click="load()">Try again</SecondaryButton>
            </div>

            <div v-else-if="state === 'missing'" class="space-y-3 rounded-md border border-line bg-surface px-4 py-6" data-testid="state-missing">
                <p class="font-medium">This dashboard no longer exists</p>
                <Link :href="links.pages.dashboards" :class="SecondaryButtonClass">Back to dashboards</Link>
            </div>

            <div v-else-if="tiles.length === 0" class="space-y-3 rounded-md border border-dashed border-line-strong px-4 py-10 text-center" data-testid="state-empty">
                <p class="font-medium">This dashboard has no tiles yet</p>
                <p class="text-sm text-muted">Add a saved insight to see it here. Save one from Trends, Funnel or Retention first if you have none.</p>
                <div class="flex flex-wrap justify-center gap-3">
                    <PrimaryButton type="button" aria-haspopup="dialog" @click="pickerOpen = true">Add tile</PrimaryButton>
                    <Link :href="links.pages.saved_insights" :class="SecondaryButtonClass">Saved insights</Link>
                </div>
            </div>

            <ul v-else class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" data-testid="tile-grid">
                <li v-for="(tile, index) in tiles" :key="tile.id" class="min-w-0">
                    <DashboardTile
                        :ref="setRef(tile.id)"
                        :tile="tile"
                        :index="index"
                        :ids="ids"
                        :run-url="runUrl(tile)"
                        :runs-url="urls.runs"
                        :detail-url="links.api.saved_insights"
                        :open-url="openUrl(tile)"
                        :queue="queue"
                        :now="now"
                        @move="onMove(tile, $event)"
                        @remove="onRemove(tile)"
                        @refreshed="status = `${$event} refreshed.`"
                    />
                </li>
            </ul>
        </section>
    </div>

    <AddTileDialog :open="pickerOpen" :insights-url="links.api.saved_insights" :dashboards-url="dashboardsApi" :dashboard-id="dashboard.id" :used-ids="usedInsightIds" :full="full" :max-tiles="limits.max_tiles" @close="pickerOpen = false" @added="added" />
    <LabelDialog :open="renameOpen" title="Rename dashboard" submit-label="Save" :initial="{ name, description: detail?.description ?? '' }" :limits="links.limits" :submit="rename" @close="renameOpen = false" @done="renamed" />
    <DeleteDialog :open="deleteOpen" title="Delete this dashboard?" confirm-label="Delete dashboard" :confirm="destroy" @close="deleteOpen = false" @done="deleted">
        <strong class="text-fg">{{ name }}</strong> and its tiles are deleted. The saved insights on it stay.
    </DeleteDialog>
</template>
