<script setup>
import OptionSelect from '@/Components/Insights/OptionSelect.vue';

// What is counted: every event, or the distinct users behind them. `options` is the server's list of metrics.
defineProps({
    options: { type: Array, required: true },
    error: { type: String, default: undefined },
});

const model = defineModel({ type: String, required: true });
</script>

<template>
    <OptionSelect v-model="model" label="Count" name="metric" :options="options" :error="error" />
</template>
