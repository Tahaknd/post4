<script setup>
import { onBeforeUnmount, ref, useId, watch } from 'vue';

// A modal confirmation built on the native <dialog>: showModal() makes everything behind it inert (a real focus
// trap), Escape closes it, and the browser returns focus to the element that had it before. The element that
// should receive focus first is the one with `autofocus` inside the slot (the safe choice: Cancel, or the field
// the user must type into); without one the browser picks the first focusable element.
//
// `open` is controlled by the parent. The dialog emits `close` whenever it closes (Escape, or the parent closing
// it), so the parent can keep its own flag in step.
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
});

const emit = defineEmits(['close']);

const dialog = ref(null);
const titleId = useId();

watch(
    () => props.open,
    (open) => {
        const element = dialog.value;

        if (!element) {
            return;
        }

        if (open && !element.open) {
            element.showModal();
        } else if (!open && element.open) {
            element.close();
        }
    },
    { flush: 'post' },
);

onBeforeUnmount(() => {
    if (dialog.value?.open) {
        dialog.value.close();
    }
});
</script>

<template>
    <!-- The native `close` event fires for Escape and for close(); the parent's state follows it. -->
    <dialog
        ref="dialog"
        :aria-labelledby="titleId"
        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-md border border-line-strong bg-surface p-6 text-fg backdrop:bg-black/60"
        @close="emit('close')"
    >
        <h2 :id="titleId" class="text-lg font-semibold tracking-tight">{{ title }}</h2>
        <div class="mt-3 space-y-4">
            <slot />
        </div>
    </dialog>
</template>
