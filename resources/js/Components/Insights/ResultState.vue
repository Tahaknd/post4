<script setup>
import { computed } from 'vue';

import { SecondaryButtonClass } from '@/Components/Insights/classes';

// The non-chart states of the results area. `kind` is one of: idle (nothing chosen yet), loading (no earlier result to
// show), empty (every value is zero), failed (a server or client error: `error` has {kind, message, retryable}),
// expired (the run is gone: run again), cancelled (the user stopped the run), blocked (the form has errors, nothing was sent). `noun` names what the page
// shows ("trend", "funnel"). `idleCopy` and `emptyCopy` ({ title, text }) replace the wording of those two states for a
// page whose insight needs other words than "pick an event" and "no events".
// Messages from the server are fixed sentences and are shown as text, never as markup.
const props = defineProps({
    kind: { type: String, required: true },
    error: { type: Object, default: null },
    noun: { type: String, default: 'result' },
    idleCopy: { type: Object, default: null },
    emptyCopy: { type: Object, default: null },
    // What to try after a failure, shown under the server's sentence.
    help: { type: String, default: null },
});

const idle = computed(() => props.idleCopy ?? { title: `Pick an event to see its ${props.noun}`, text: 'Choose one or more events above. The chart appears here.' });
const empty = computed(() => props.emptyCopy ?? { title: 'No events in this range', text: 'The selected events have no occurrences with these filters. Try a longer range or remove a filter.' });

const emit = defineEmits(['retry', 'reload']);

const RELOAD_KINDS = ['unauthenticated', 'page_expired'];
</script>

<template>
    <div v-if="kind === 'idle'" class="rounded-md border border-dashed border-line-strong px-4 py-10 text-center" data-testid="state-idle">
        <p class="font-medium">{{ idle.title }}</p>
        <p class="mt-1 text-sm text-muted">{{ idle.text }}</p>
    </div>

    <div v-else-if="kind === 'loading'" class="space-y-3" data-testid="state-loading" aria-hidden="true">
        <div class="h-6 w-1/3 animate-pulse rounded bg-line"></div>
        <div class="h-72 w-full animate-pulse rounded-md bg-line sm:h-96"></div>
    </div>

    <div v-else-if="kind === 'empty'" class="rounded-md border border-line bg-surface px-4 py-10 text-center" data-testid="state-empty">
        <p class="font-medium">{{ empty.title }}</p>
        <p class="mt-1 text-sm text-muted">{{ empty.text }}</p>
    </div>

    <div v-else-if="kind === 'blocked'" class="rounded-md border border-line bg-surface px-4 py-6" data-testid="state-blocked">
        <p class="font-medium">Complete or fix the fields above to run the query</p>
    </div>

    <div v-else-if="kind === 'expired'" class="rounded-md border border-line bg-surface px-4 py-6 space-y-3" data-testid="state-expired">
        <p class="font-medium">This result is no longer available</p>
        <p class="text-sm text-muted">The run expired or was cancelled before it finished.</p>
        <button type="button" :class="SecondaryButtonClass" @click="emit('retry')">Run again</button>
    </div>

    <div v-else-if="kind === 'cancelled'" class="space-y-3 rounded-md border border-line bg-surface px-4 py-6" data-testid="state-cancelled">
        <p class="font-medium">The run was cancelled</p>
        <p class="text-sm text-muted">Nothing was changed. Run it again when you are ready.</p>
        <button type="button" :class="SecondaryButtonClass" @click="emit('retry')">Run again</button>
    </div>

    <div v-else-if="kind === 'failed'" class="space-y-3 rounded-md border border-danger px-4 py-6" role="alert" data-testid="state-failed">
        <p class="font-medium text-danger">{{ error?.message ?? 'The query could not be completed. Try again.' }}</p>
        <p v-if="help" class="text-sm text-muted" data-testid="failure-help">{{ help }}</p>
        <div class="flex flex-wrap gap-3">
            <button v-if="error && RELOAD_KINDS.includes(error.kind)" type="button" :class="SecondaryButtonClass" @click="emit('reload')">Reload the page</button>
            <button v-else-if="error?.retryable !== false" type="button" :class="SecondaryButtonClass" @click="emit('retry')">Try again</button>
        </div>
    </div>
</template>
