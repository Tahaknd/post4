<script setup>
import { computed } from 'vue';

import { SecondaryButtonClass } from '@/Components/Insights/classes';
import { rangeText } from '@/lib/pagination';

// Previous / Next over a list that is already loaded, with the range as text. aria-disabled, never natively disabled, so
// the button that was just pressed keeps focus at either end.
const props = defineProps({
    paging: { type: Object, required: true },
    noun: { type: String, default: 'results' },
});

const emit = defineEmits(['page']);

const text = computed(() => rangeText(props.paging, props.noun));
</script>

<template>
    <nav v-if="paging.lastPage > 1" :aria-label="`${noun} pages`" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted" data-testid="pager-text">{{ text }}</p>
        <div class="flex items-center gap-2">
            <button type="button" :class="SecondaryButtonClass" :aria-disabled="paging.page <= 1 ? 'true' : undefined" class="aria-disabled:cursor-not-allowed aria-disabled:opacity-60" @click="paging.page > 1 && emit('page', paging.page - 1)">Previous</button>
            <span class="text-sm tabular-nums" aria-current="page">Page {{ paging.page }} of {{ paging.lastPage }}</span>
            <button type="button" :class="SecondaryButtonClass" :aria-disabled="paging.page >= paging.lastPage ? 'true' : undefined" class="aria-disabled:cursor-not-allowed aria-disabled:opacity-60" @click="paging.page < paging.lastPage && emit('page', paging.page + 1)">Next</button>
        </div>
    </nav>
</template>
