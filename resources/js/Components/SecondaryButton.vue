<script setup>
defineProps({
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <button
        :type="type"
        :aria-disabled="disabled ? 'true' : undefined"
        class="inline-flex items-center justify-center rounded-md border border-line-strong bg-surface px-4 py-2 font-medium text-fg hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
    >
        <slot />
    </button>
</template>
