<script setup>
import { ref, watch } from 'vue';

import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

// A confirmation before something is deleted. Cancel has the focus first (the safe choice). `confirm()` is the page's call of
// the API and answers a lib/dashboardsApi result; a failure is shown inside the dialog, which then stays open.
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    confirmLabel: { type: String, default: 'Delete' },
    confirm: { type: Function, required: true },
});

const emit = defineEmits(['close', 'done']);

const busy = ref(false);
const error = ref('');

watch(
    () => props.open,
    (open) => {
        if (open) {
            busy.value = false;
            error.value = '';
        }
    },
);

async function remove() {
    if (busy.value) {
        return;
    }

    busy.value = true;
    const answer = await props.confirm();
    busy.value = false;

    if (answer.ok || answer.kind === 'not_found') {
        emit('done');
        emit('close');
    } else {
        error.value = answer.message;
    }
}
</script>

<template>
    <ConfirmDialog :open="open" :title="title" @close="emit('close')">
        <template #description><slot /></template>
        <p v-if="error" role="alert" class="rounded-md border border-danger px-3 py-2 text-sm text-danger" data-testid="dialog-error">{{ error }}</p>
        <div class="flex justify-end gap-3">
            <SecondaryButton type="button" autofocus @click="emit('close')">Cancel</SecondaryButton>
            <DangerButton type="button" :disabled="busy" @click="remove">{{ busy ? 'Deleting…' : confirmLabel }}</DangerButton>
        </div>
    </ConfirmDialog>
</template>
