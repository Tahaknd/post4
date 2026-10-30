<script setup>
import { computed } from 'vue';

import { typeLabel } from '@/lib/savedInsightState';

// The kind of a saved insight, as text (colour is never the only signal).
const props = defineProps({ type: { type: String, required: true } });

const label = computed(() => typeLabel(props.type));
</script>

<template>
    <span class="inline-flex items-center rounded-md border border-line-strong px-2 py-0.5 text-xs font-medium" data-testid="type-badge">{{ label }}</span>
</template>
