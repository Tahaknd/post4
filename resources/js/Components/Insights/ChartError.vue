<script setup>
import { SecondaryButtonClass } from '@/Components/Insights/classes';

// Shown when the chart's code could not be loaded (the lazy chunk failed: a lost connection, a deploy that replaced the
// file). The table under the chart has the same numbers, so nothing is lost.
const emit = defineEmits(['retry']);
</script>

<template>
    <div role="alert" class="space-y-3 rounded-md border border-danger px-4 py-4" data-testid="chart-error">
        <p class="text-danger">The chart could not be loaded. The table below has the same numbers.</p>
        <button type="button" :class="SecondaryButtonClass" @click="emit('retry')">Try again</button>
    </div>
</template>
