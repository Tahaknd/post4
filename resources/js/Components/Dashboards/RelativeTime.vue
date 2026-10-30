<script setup>
import { computed } from 'vue';

import { formatAbsolute, formatRelative, isoOf } from '@/lib/relativeTime';

// "5 minutes ago" for the eye, the exact time in the title and in `datetime` for everything else. `now` is the page's clock.
const props = defineProps({
    at: { type: String, required: true },
    now: { type: Number, required: true },
    prefix: { type: String, default: '' },
});

const text = computed(() => `${props.prefix}${formatRelative(props.at, props.now)}`);
</script>

<template>
    <time :datetime="isoOf(at)" :title="formatAbsolute(at)">{{ text }}</time>
</template>
