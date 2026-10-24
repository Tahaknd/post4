<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectField from '@/Components/SelectField.vue';
import TextField from '@/Components/TextField.vue';
import { useFormFocus } from '@/composables/useFormFocus';

// `timezones` is the flat list of IANA identifiers the server accepts; it is grouped by region for a native
// <select> (keyboard type-ahead works, and it needs no script to be accessible).
const props = defineProps({
    timezones: { type: Array, required: true },
    default_timezone: { type: String, required: true },
    store_url: { type: String, required: true },
    index_url: { type: String, required: true },
});

const form = useForm({ name: '', timezone: props.default_timezone });
const { formRef, alertRef } = useFormFocus(form);

const groups = computed(() => {
    const byRegion = new Map();

    for (const zone of props.timezones) {
        const region = zone.includes('/') ? zone.split('/')[0] : 'Other';

        if (!byRegion.has(region)) {
            byRegion.set(region, []);
        }

        byRegion.get(region).push(zone);
    }

    return [...byRegion].map(([label, options]) => ({ label, options }));
});

function submit() {
    if (form.processing) {
        return;
    }

    form.post(props.store_url);
}
</script>

<template>
    <Head title="New project" />

    <section aria-labelledby="create-heading" class="mx-auto w-full max-w-md space-y-6">
        <h1 id="create-heading" class="text-2xl font-semibold tracking-tight">New project</h1>

        <p v-if="form.errors.form" ref="alertRef" role="alert" tabindex="-1" class="rounded-md border border-danger px-3 py-2 text-sm text-danger">
            {{ form.errors.form }}
        </p>

        <form ref="formRef" class="space-y-4" novalidate @submit.prevent="submit">
            <TextField v-model="form.name" label="Name" name="name" autocomplete="off" :error="form.errors.name" required autofocus />
            <SelectField
                v-model="form.timezone"
                label="Timezone"
                name="timezone"
                :groups="groups"
                hint="Reports use this timezone to cut days."
                :error="form.errors.timezone"
                required
            />

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Create project</PrimaryButton>
                <Link :href="index_url" class="font-medium text-accent hover:text-accent-hover">Cancel</Link>
            </div>
        </form>
    </section>
</template>
