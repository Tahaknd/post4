<script setup>
import OptionSelect from '@/Components/Insights/OptionSelect.vue';

// How wide one point of the chart is. `options` is the server's list of intervals.
defineProps({
    options: { type: Array, required: true },
    error: { type: String, default: undefined },
});

const model = defineModel({ type: String, required: true });
</script>

<template>
    <OptionSelect v-model="model" label="Interval" name="interval" :options="options" :error="error" />
</template>
