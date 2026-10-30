<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

import RelativeTime from '@/Components/Dashboards/RelativeTime.vue';
import TileResult from '@/Components/Dashboards/TileResult.vue';
import TypeBadge from '@/Components/Dashboards/TypeBadge.vue';
import { SmallButtonClass } from '@/Components/Insights/classes';
import { useInsightRun } from '@/composables/useInsightRun';
import { getSavedInsight } from '@/lib/dashboardsApi';
import { canMove } from '@/lib/tileOrder';
import { needsReview, reasonText, tileView, canRepair } from '@/lib/savedInsightState';
import { EMPTY_TEXT, isDrawable, isEmptyFor, trendsMeta } from '@/lib/tileResult';

// One tile of a dashboard: the title of the saved insight with its type, the result in compact form, when it was computed, and the
// actions (Refresh, Open, Move earlier / later, Remove from dashboard).
//
// It runs its insight through `POST saved-insights/{id}/run` with the ordinary insight run client: a stored result is answered at
// once (`computed_at` says when it was computed), a queued run is polled, and the run is cancelled when the tile goes away. Three
// rules keep a big dashboard cheap: the tile asks only when it comes into view (IntersectionObserver), it asks through the
// page's RunQueue (at most `limit` runs at once, the rest wait in order), and the chart code is lazy (LazyChart). The tile has a
// fixed minimum height, the same for every state, so a result arriving moves nothing.
const props = defineProps({
    tile: { type: Object, required: true },
    index: { type: Number, required: true },
    ids: { type: Array, required: true },
    runUrl: { type: String, default: null },
    runsUrl: { type: String, required: true },
    detailUrl: { type: String, default: null },
    openUrl: { type: String, default: null },
    queue: { type: Object, required: true },
    now: { type: Number, required: true },
});

const emit = defineEmits(['remove', 'move', 'refreshed']);

const insight = computed(() => props.tile.saved_insight);
const broken = computed(() => needsReview(insight.value));
const key = computed(() => props.tile.id);

const root = ref(null);
const run = useInsightRun(props.runUrl ?? '/', props.runsUrl);
const state = computed(() => run.state.value);

const requested = ref(false);
const waiting = ref(false);
const manual = ref(false);
const local = shallowRef(null); // an error of the tile itself (the definition could not be read)
const shown = shallowRef(null); // { result, at }: the result on screen stays while a refresh runs
const meta = shallowRef(null);
let observer = null;
let disposed = false;

// ------------------------------------------------------------ asking
function request(fresh) {
    if (broken.value || props.runUrl === null || waiting.value || props.queue.isActive(key.value)) {
        return;
    }

    requested.value = true;
    local.value = null;
    waiting.value = true;
    manual.value = fresh;
    props.queue.enqueue(key.value, () => begin(fresh));
}

async function begin(fresh) {
    waiting.value = false;
    if (disposed) {
        props.queue.release(key.value);

        return;
    }

    // A trends result does not say its interval or metric: read them from the saved definition (once).
    if (insight.value.type === 'trends' && meta.value === null) {
        const answer = props.detailUrl === null ? null : await getSavedInsight(props.detailUrl, insight.value.id);
        if (disposed) {
            props.queue.release(key.value);

            return;
        }
        meta.value = answer?.ok ? trendsMeta(answer.data) : null;
        if (meta.value === null) {
            local.value = { message: answer?.message ?? 'The definition of this insight could not be read. Try again.', retryable: true, retry_after_seconds: null };
            props.queue.release(key.value);

            return;
        }
    }

    run.run(fresh ? { fresh: true } : {});
}

// A finished run gives its slot back and, when it produced a result, replaces the one on screen.
watch(
    () => state.value,
    (next) => {
        if (next.phase === 'loading') {
            return;
        }
        props.queue.release(key.value);

        if (next.phase === 'done' && isDrawable(insight.value.type, next.result)) {
            shown.value = { result: next.result, at: next.computedAt ?? new Date().toISOString() };
            if (manual.value) {
                emit('refreshed', insight.value.name);
            }
        }
        manual.value = false;
    },
);

onMounted(() => {
    if (broken.value) {
        return;
    }
    if (typeof IntersectionObserver === 'undefined') {
        request(false);

        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                observer?.disconnect();
                observer = null;
                request(false);
            }
        },
        { rootMargin: '200px' },
    );
    observer.observe(root.value);
});

onBeforeUnmount(() => {
    disposed = true;
    observer?.disconnect();
    props.queue.cancel(key.value);
});

// ------------------------------------------------------------ what the body shows
const view = computed(() => {
    if (local.value !== null) {
        return 'failed';
    }
    if (!broken.value && shown.value !== null && (waiting.value || state.value.phase === 'loading' || state.value.phase === 'idle' || state.value.phase === 'done')) {
        return isEmptyFor(insight.value.type, shown.value.result) ? 'empty' : 'result';
    }

    return tileView({ insight: insight.value, phase: requested.value ? state.value.phase : 'loading', waiting: waiting.value, hasResult: false, empty: false });
});

const refreshing = computed(() => waiting.value || state.value.phase === 'loading');
const error = computed(() => local.value ?? (state.value.phase === 'done' ? { message: 'The server sent an answer this tile cannot draw. Try again.', retryable: true, retry_after_seconds: null } : state.value.error));
const stageText = computed(() => (waiting.value ? 'Waiting for a free slot…' : state.value.stage === 'queued' ? 'Queued…' : (state.value.notice ?? 'Running…')));
const retryText = computed(() => (error.value?.retry_after_seconds ? `The server asked to wait ${error.value.retry_after_seconds} seconds before trying again.` : null));
const titleId = computed(() => `tile-${props.tile.id}`);

// ------------------------------------------------------------ actions
const earlier = ref(null);
const later = ref(null);

const canEarlier = computed(() => canMove(props.ids, props.tile.id, -1));
const canLater = computed(() => canMove(props.ids, props.tile.id, 1));

/** After a move the tile's DOM node was moved: put focus back on the button that was used, or on its twin at either end. */
async function focusMove(delta) {
    await nextTick();
    const first = delta < 0 ? earlier : later;
    const twin = delta < 0 ? later : earlier;
    const usable = delta < 0 ? canEarlier.value : canLater.value;
    (usable ? first : twin).value?.focus();
}

defineExpose({ refresh: () => request(true), focusMove, focusTitle: () => root.value?.querySelector('h2')?.focus() });
</script>

<template>
    <article ref="root" :aria-labelledby="titleId" class="flex min-h-[33rem] flex-col gap-3 rounded-md border border-line bg-surface p-4" data-testid="dashboard-tile" :data-state="view">
        <header class="flex items-start justify-between gap-2">
            <h2 :id="titleId" tabindex="-1" class="min-w-0 text-base font-semibold tracking-tight break-words focus:outline-none" :title="insight.name" data-testid="tile-title">{{ insight.name }}</h2>
            <TypeBadge :type="insight.type" />
        </header>

        <div class="flex min-h-9 flex-wrap items-center justify-between gap-2 text-sm text-muted">
            <p data-testid="tile-updated">
                <template v-if="shown"><RelativeTime :at="shown.at" :now="now" prefix="Updated " /></template>
                <template v-else-if="refreshing">{{ stageText }}</template>
                <template v-else>&nbsp;</template>
            </p>
            <button v-if="!broken" type="button" :class="SmallButtonClass" :aria-disabled="refreshing ? 'true' : undefined" :aria-label="`Refresh ${insight.name}`" @click="request(true)">{{ refreshing && shown ? 'Refreshing…' : 'Refresh' }}</button>
        </div>

        <div class="min-h-0 flex-1" :aria-busy="refreshing ? 'true' : 'false'">
            <TileResult v-if="view === 'result'" :type="insight.type" :result="shown.result" :meta="meta" />

            <div v-else-if="view === 'empty'" class="flex h-full min-h-[19rem] flex-col items-center justify-center rounded-md border border-dashed border-line-strong px-4 text-center" data-testid="tile-empty">
                <p class="font-medium">No data</p>
                <p class="mt-1 text-sm text-muted">{{ EMPTY_TEXT[insight.type] }}</p>
            </div>

            <div v-else-if="view === 'needs_review'" class="flex h-full min-h-[19rem] flex-col justify-center space-y-3 rounded-md border border-warning px-4 py-4" data-testid="tile-needs-review">
                <p class="font-medium text-warning">Needs review</p>
                <p class="text-sm">{{ reasonText(insight.state) }}</p>
                <Link v-if="canRepair(insight) && openUrl" :href="openUrl" :class="SmallButtonClass" class="self-start" :aria-label="`Open ${insight.name} to repair`">Open to repair</Link>
            </div>

            <div v-else-if="view === 'failed'" class="flex h-full min-h-[19rem] flex-col justify-center space-y-3 rounded-md border border-danger px-4 py-4" role="alert" data-testid="tile-failed">
                <p class="font-medium text-danger">{{ error?.message ?? 'This insight could not be loaded. Try again.' }}</p>
                <p v-if="retryText" class="text-sm text-muted">{{ retryText }}</p>
                <button v-if="error?.retryable !== false" type="button" :class="SmallButtonClass" class="self-start" :aria-label="`Try again: ${insight.name}`" @click="request(false)">Try again</button>
            </div>

            <div v-else-if="view === 'expired' || view === 'cancelled'" class="flex h-full min-h-[19rem] flex-col justify-center space-y-3 rounded-md border border-line px-4 py-4" data-testid="tile-expired">
                <p class="font-medium">{{ view === 'expired' ? 'This result is no longer available' : 'The run was cancelled' }}</p>
                <button type="button" :class="SmallButtonClass" class="self-start" :aria-label="`Run again: ${insight.name}`" @click="request(false)">Run again</button>
            </div>

            <div v-else class="flex h-full min-h-[19rem] flex-col justify-center gap-3" data-testid="tile-loading" role="status">
                <div class="h-44 w-full animate-pulse rounded-md bg-line" aria-hidden="true"></div>
                <p class="text-sm text-muted">{{ requested ? stageText : 'Loads when it comes into view' }}</p>
            </div>
        </div>

        <footer class="flex min-h-[4.5rem] flex-wrap content-start gap-2 border-t border-line pt-3">
            <Link v-if="openUrl && canRepair(insight) && !broken" :href="openUrl" :class="SmallButtonClass" :aria-label="`Open ${insight.name}`">Open</Link>
            <button ref="earlier" type="button" :class="SmallButtonClass" :aria-disabled="canEarlier ? undefined : 'true'" :aria-label="`Move earlier: ${insight.name}`" @click="canEarlier && emit('move', -1)">Move earlier</button>
            <button ref="later" type="button" :class="SmallButtonClass" :aria-disabled="canLater ? undefined : 'true'" :aria-label="`Move later: ${insight.name}`" @click="canLater && emit('move', 1)">Move later</button>
            <button type="button" :class="SmallButtonClass" :aria-label="`Remove from dashboard: ${insight.name}`" @click="emit('remove')">Remove from dashboard</button>
        </footer>
    </article>
</template>
