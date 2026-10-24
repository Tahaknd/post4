<script setup>
import { onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

// A modal confirmation built on the native <dialog>: showModal() makes everything behind it inert (a real focus
// trap), Escape closes it, and the browser returns focus to the element that had it before. The element that
// should receive focus first is the one with `autofocus` inside the slot (the safe choice: Cancel, or the field
// the user must type into); without one the browser picks the first focusable element.
//
// The `description` slot is what the dialog is about, tied to it with aria-describedby (read after the title);
// the default slot holds the controls. While the dialog is open the page behind it cannot scroll, and a dialog
// taller than a small screen scrolls inside itself instead of running off it.
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
const descriptionId = useId();

const SCROLL_LOCK = 'overflow-hidden';

function sync(open) {
    const element = dialog.value;

    if (!element) {
        return;
    }

    if (open && !element.open) {
        element.showModal();
        document.documentElement.classList.add(SCROLL_LOCK);
    } else if (!open && element.open) {
        element.close();
    }
}

function released() {
    document.documentElement.classList.remove(SCROLL_LOCK);
    emit('close');
}

// Also on mount: a dialog that is created already open has no element yet when the first watcher run happens.
watch(() => props.open, sync, { flush: 'post', immediate: true });
onMounted(() => sync(props.open));

onBeforeUnmount(() => {
    if (dialog.value?.open) {
        dialog.value.close();
    }

    document.documentElement.classList.remove(SCROLL_LOCK);
});
</script>

<template>
    <!-- The native `close` event fires for Escape and for close(); the parent's state follows it. -->
    <dialog
        ref="dialog"
        :aria-labelledby="titleId"
        :aria-describedby="descriptionId"
        class="m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-md border border-line-strong bg-surface p-6 text-fg backdrop:bg-black/60"
        @close="released"
    >
        <h2 :id="titleId" class="text-lg font-semibold tracking-tight">{{ title }}</h2>
        <p :id="descriptionId" class="mt-3 text-sm text-muted"><slot name="description" /></p>
        <div class="mt-4 space-y-4">
            <slot />
        </div>
    </dialog>
</template>
