<script setup>
// Same contract as PrimaryButton: `disabled` is exposed as aria-disabled so the button keeps keyboard focus,
// and the caller's handler must ignore a repeated activation itself.
defineProps({
    type: { type: String, default: 'submit' },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <button
        :type="type"
        :aria-disabled="disabled ? 'true' : undefined"
        class="inline-flex items-center justify-center rounded-md bg-danger px-4 py-2 font-medium text-danger-fg hover:opacity-90 aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
    >
        <slot />
    </button>
</template>
