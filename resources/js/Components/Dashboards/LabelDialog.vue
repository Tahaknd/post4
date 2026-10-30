<script setup>
import { computed, ref, watch } from 'vue';

import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextAreaField from '@/Components/TextAreaField.vue';
import TextField from '@/Components/TextField.vue';
import { mapSaveErrors, validateLabels } from '@/lib/saveInsight';

// A name and an optional description in a modal (create or rename a dashboard, rename a saved insight). The dialog is the
// native one of ConfirmDialog (focus trap, Escape, focus back to the opener). `submit({ name, description })` is the page's
// call of the API and answers the result of lib/dashboardsApi: a 422 puts its messages on the fields, anything else is
// shown as one alert in the dialog, which stays open so nothing typed is lost.
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    submitLabel: { type: String, required: true },
    initial: { type: Object, default: () => ({ name: '', description: '' }) },
    limits: { type: Object, required: true },
    submit: { type: Function, required: true },
});

const emit = defineEmits(['close', 'done']);

const name = ref('');
const description = ref('');
const errors = ref({});
const busy = ref(false);

watch(
    () => props.open,
    (open) => {
        if (open) {
            name.value = props.initial.name ?? '';
            description.value = props.initial.description ?? '';
            errors.value = {};
            busy.value = false;
        }
    },
    { immediate: true },
);

const limitsOf = computed(() => ({ name: props.limits.name_max, description: props.limits.description_max }));

async function send() {
    if (busy.value) {
        return;
    }

    const instant = validateLabels({ name: name.value, description: description.value }, limitsOf.value);
    errors.value = instant;
    if (Object.keys(instant).length > 0) {
        return;
    }

    busy.value = true;
    const answer = await props.submit({ name: name.value.trim(), description: description.value.trim() });
    busy.value = false;

    if (answer.ok) {
        emit('done', answer.data);
        emit('close');
    } else if (answer.kind === 'validation') {
        errors.value = mapSaveErrors(answer.errors);
        if (Object.keys(errors.value).length === 0) {
            errors.value = { general: answer.message };
        }
    } else {
        errors.value = { general: answer.message };
    }
}
</script>

<template>
    <ConfirmDialog :open="open" :title="title" @close="emit('close')">
        <template #description><slot name="description">Give it a name people will recognise. The description is optional.</slot></template>
        <form class="space-y-4" novalidate @submit.prevent="send">
            <TextField v-model="name" label="Name" name="name" autocomplete="off" :error="errors.name" required autofocus />
            <TextAreaField v-model="description" label="Description" name="description" :error="errors.description" />
            <p v-if="errors.general" role="alert" class="rounded-md border border-danger px-3 py-2 text-sm text-danger" data-testid="dialog-error">{{ errors.general }}</p>
            <div class="flex justify-end gap-3">
                <SecondaryButton type="button" @click="emit('close')">Cancel</SecondaryButton>
                <PrimaryButton :disabled="busy">{{ busy ? 'Saving…' : submitLabel }}</PrimaryButton>
            </div>
        </form>
    </ConfirmDialog>
</template>
