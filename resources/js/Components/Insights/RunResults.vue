<script setup>
import ResultState from '@/Components/Insights/ResultState.vue';

// The results area of an insight that runs on a button: the live region (always in the DOM: a live region only announces text
// that is inserted into one that already exists) and the Results region, which holds the result (the default slot, shown
// when `view` is "result") or the state that stands in for it. `noun` names the insight in the sentences ("funnel").
defineProps({
    view: { type: String, required: true },
    status: { type: String, required: true },
    busy: { type: Boolean, required: true },
    stale: { type: Boolean, required: true },
    noun: { type: String, required: true },
    error: { type: Object, default: null },
    help: { type: String, default: null },
    idleCopy: { type: Object, required: true },
    emptyCopy: { type: Object, required: true },
});

const emit = defineEmits(['retry']);

function reload() {
    window.location.reload();
}
</script>

<template>
    <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-testid="status">{{ status }}</div>

    <section aria-label="Results" :aria-busy="busy ? 'true' : 'false'" class="space-y-3">
        <p v-if="stale" class="text-sm text-muted" data-testid="status-stale">The form has changed since this result. Run the {{ noun }} again to update it.</p>

        <slot v-if="view === 'result'" />

        <ResultState v-else :kind="view" :error="error" :help="help" :idle-copy="idleCopy" :empty-copy="emptyCopy" :noun="noun" @retry="emit('retry')" @reload="reload" />
    </section>
</template>
