<script setup>
import { onBeforeUnmount, ref } from 'vue';

import SecondaryButton from '@/Components/SecondaryButton.vue';

// Copies `text` to the clipboard and tells assistive technology what happened through a live region that is
// always in the DOM (a live region only announces text inserted into an existing one). The clipboard API needs a
// secure context (https or localhost) and can be refused (embedded browsers do): then the older
// document.execCommand('copy') on a throwaway textarea is tried, and a failure of both is reported, never swallowed.
const props = defineProps({
    text: { type: String, required: true },
    label: { type: String, default: 'Copy' },
});

const message = ref('');
let timer = null;

function copyWithSelection() {
    const field = document.createElement('textarea');
    const focused = document.activeElement;

    field.value = props.text;
    field.setAttribute('readonly', '');
    field.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
    document.body.appendChild(field);
    field.select();

    try {
        return document.execCommand('copy');
    } finally {
        field.remove();
        focused?.focus?.();
    }
}

async function copy() {
    clearTimeout(timer);

    let copied = false;

    try {
        await navigator.clipboard.writeText(props.text);
        copied = true;
    } catch {
        try {
            copied = copyWithSelection();
        } catch {
            copied = false;
        }
    }

    message.value = copied ? 'Copied to the clipboard.' : 'Copying failed. Select the text and copy it by hand.';

    timer = setTimeout(() => (message.value = ''), 4000);
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <span class="inline-flex items-center gap-3">
        <SecondaryButton @click="copy">{{ label }}</SecondaryButton>
        <span role="status" aria-live="polite" class="text-sm text-muted">{{ message }}</span>
    </span>
</template>
