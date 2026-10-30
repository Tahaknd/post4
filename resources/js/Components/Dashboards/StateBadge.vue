<script setup>
import { computed } from 'vue';

import { reasonText, stateBadge } from '@/lib/savedInsightState';

// OK or Needs review. The reason of a broken one is the title and, for assistive technology, part of the name.
const props = defineProps({ insight: { type: Object, required: true } });

const badge = computed(() => stateBadge(props.insight));
const broken = computed(() => badge.value.tone === 'warning');
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium"
        :class="broken ? 'border-warning text-warning' : 'border-line-strong text-muted'"
        :title="broken ? reasonText(insight.state) : undefined"
        data-testid="state-badge"
    >
        <span aria-hidden="true">{{ broken ? '!' : '✓' }}</span>{{ badge.label }}
    </span>
</template>
