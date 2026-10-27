<script setup>
import { SecondaryButtonClass } from '@/Components/Insights/classes';

// The non-chart states of the results area. `kind` is one of: idle (no event chosen), loading (no earlier result to
// show), empty (every value is zero), failed (a server or client error: `error` has {kind, message, retryable}),
// expired (the run is gone: run again), blocked (the form has errors, nothing was sent).
// Messages from the server are fixed sentences and are shown as text, never as markup.
defineProps({
    kind: { type: String, required: true },
    error: { type: Object, default: null },
    notice: { type: String, default: null },
});

const emit = defineEmits(['retry', 'reload']);

const RELOAD_KINDS = ['unauthenticated', 'page_expired'];
</script>

<template>
    <div v-if="kind === 'idle'" class="rounded-md border border-dashed border-line-strong px-4 py-10 text-center" data-testid="state-idle">
        <p class="font-medium">Pick an event to see its trend</p>
        <p class="mt-1 text-sm text-muted">Choose one or more events above. The chart appears here.</p>
    </div>

    <div v-else-if="kind === 'loading'" class="space-y-3" data-testid="state-loading" aria-hidden="true">
        <div class="h-6 w-1/3 animate-pulse rounded bg-line"></div>
        <div class="h-72 w-full animate-pulse rounded-md bg-line sm:h-96"></div>
    </div>

    <div v-else-if="kind === 'empty'" class="rounded-md border border-line bg-surface px-4 py-10 text-center" data-testid="state-empty">
        <p class="font-medium">No events in this range</p>
        <p class="mt-1 text-sm text-muted">The selected events have no occurrences with these filters. Try a longer range or remove a filter.</p>
    </div>

    <div v-else-if="kind === 'blocked'" class="rounded-md border border-line bg-surface px-4 py-6" data-testid="state-blocked">
        <p class="font-medium">Fix the highlighted fields to run the query</p>
    </div>

    <div v-else-if="kind === 'expired'" class="rounded-md border border-line bg-surface px-4 py-6 space-y-3" data-testid="state-expired">
        <p class="font-medium">This result is no longer available</p>
        <p class="text-sm text-muted">The run expired or was cancelled before it finished.</p>
        <button type="button" :class="SecondaryButtonClass" @click="emit('retry')">Run again</button>
    </div>

    <div v-else-if="kind === 'failed'" class="space-y-3 rounded-md border border-danger px-4 py-6" role="alert" data-testid="state-failed">
        <p class="font-medium text-danger">{{ error?.message ?? 'The query could not be completed. Try again.' }}</p>
        <div class="flex flex-wrap gap-3">
            <button v-if="error && RELOAD_KINDS.includes(error.kind)" type="button" :class="SecondaryButtonClass" @click="emit('reload')">Reload the page</button>
            <button v-else-if="error?.retryable !== false" type="button" :class="SecondaryButtonClass" @click="emit('retry')">Try again</button>
        </div>
    </div>
</template>
