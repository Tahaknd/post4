<script setup>
// `disabled` is exposed as aria-disabled, not as the native attribute: a natively disabled button drops out of
// the tab order and loses keyboard focus the moment a form is submitted (the very button the user just pressed).
// The button stays focusable and announces itself as unavailable; the page's submit handler must ignore
// repeated submissions itself (`if (form.processing) return`), because aria-disabled does not stop a click.
defineProps({
    type: { type: String, default: 'submit' },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <button
        :type="type"
        :aria-disabled="disabled ? 'true' : undefined"
        class="inline-flex items-center justify-center rounded-md bg-accent px-4 py-2 font-medium text-accent-fg hover:bg-accent-hover aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
    >
        <slot />
    </button>
</template>
