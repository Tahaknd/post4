<script setup>
import { computed, ref, useId, watch } from 'vue';

import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextAreaField from '@/Components/TextAreaField.vue';
import TextField from '@/Components/TextField.vue';
import { addTile, createDashboard, createSavedInsight, listAll } from '@/lib/dashboardsApi';
import { buildSaveBody, mapSaveErrors, savePlan, validateLabels } from '@/lib/saveInsight';

// "Save insight" for the Trends, Funnel and Retention pages: a name (required), a description (optional) and, optionally, a
// dashboard to put the insight on (one that exists, or a new one). The body is the body the page sends to run the insight, taken
// the moment the dialog is submitted, so a preset stays "last 7 days" and absolute days stay absolute (lib/saveInsight).
//
// Saving is up to three calls in order: the saved insight, the new dashboard (if asked for) and the tile. A step that is done is
// never repeated: when a later step fails the dialog stays open, says what was saved and what was not, and its button retries
// only the rest. A 422 puts its messages on the fields (name, description, the new dashboard's name); anything else, such as a
// limit, shows as one alert.
const props = defineProps({
    open: { type: Boolean, default: false },
    type: { type: String, required: true },
    query: { type: Function, required: true },
    saving: { type: Object, required: true },
    suggestedName: { type: String, default: '' },
    title: { type: String, default: 'Save insight' },
});

const emit = defineEmits(['close', 'saved']);

const name = ref('');
const description = ref('');
const choice = ref('none'); // none | new | a dashboard id
const dashboardName = ref('');
const dashboards = ref([]);
const dashboardsState = ref('loading');
const errors = ref({});
const busy = ref(false);

// What is done already (a retry skips it).
const insight = ref(null);
const dashboard = ref(null);
const selectId = useId();
let announced = false;

const target = computed(() => (choice.value === 'none' ? { mode: 'none' } : choice.value === 'new' ? { mode: 'new', name: dashboardName.value.trim() } : { mode: 'existing', id: choice.value }));
const partial = computed(() => insight.value !== null);
const limits = computed(() => ({ name: props.saving.limits.name_max, description: props.saving.limits.description_max }));

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            return;
        }

        name.value = props.suggestedName;
        description.value = '';
        choice.value = 'none';
        dashboardName.value = '';
        errors.value = {};
        busy.value = false;
        insight.value = null;
        dashboard.value = null;
        announced = false;

        dashboardsState.value = 'loading';
        const answer = await listAll(props.saving.api.dashboards);
        dashboards.value = answer.ok ? answer.data : [];
        dashboardsState.value = answer.ok ? 'ready' : 'failed';
    },
    { immediate: true },
);

async function send() {
    if (busy.value) {
        return;
    }

    const instant = validateLabels({ name: name.value, description: description.value }, limits.value);
    if (choice.value === 'new') {
        const named = validateLabels({ name: dashboardName.value, description: '' }, limits.value);
        if (named.name !== undefined) {
            instant.dashboardName = named.name.replace('Enter a name.', 'Enter a name for the new dashboard.');
        }
    }
    errors.value = instant;
    if (Object.keys(instant).length > 0) {
        return;
    }

    busy.value = true;
    const failure = await run();
    busy.value = false;

    if (failure === null) {
        announced = true;
        emit('saved', { insight: insight.value, dashboard: dashboard.value, added: choice.value !== 'none' });
        emit('close');
    }
}

// The insight is saved even when the dashboard step failed and the dialog is closed (button or Escape): tell the page.
function close() {
    if (partial.value && !announced) {
        announced = true;
        emit('saved', { insight: insight.value, dashboard: null, added: false });
    }
    emit('close');
}

/** Runs the steps that are not done yet; returns null when all passed, else puts the problem on the dialog and returns it. */
async function run() {
    for (const step of savePlan({ target: target.value, insightId: insight.value?.id ?? null, dashboardId: dashboard.value?.id ?? null })) {
        if (step === 'insight') {
            const answer = await createSavedInsight(props.saving.api.saved_insights, buildSaveBody({ type: props.type, name: name.value, description: description.value, query: props.query() }));
            if (!answer.ok) {
                errors.value = answer.kind === 'validation' ? mapSaveErrors(answer.errors) : { general: answer.message };
                if (answer.kind === 'validation' && Object.keys(errors.value).length === 0) {
                    errors.value = { general: answer.message };
                }

                return answer;
            }
            insight.value = answer.data;
        } else if (step === 'dashboard') {
            const answer = await createDashboard(props.saving.api.dashboards, { name: dashboardName.value.trim() });
            if (!answer.ok) {
                const mapped = answer.kind === 'validation' ? mapSaveErrors(answer.errors) : { general: answer.message };
                errors.value = { general: `The insight was saved, but the dashboard was not created. ${mapped.name ?? mapped.general ?? answer.message}` };

                return answer;
            }
            dashboard.value = answer.data;
        } else {
            const dashboardId = choice.value === 'new' ? dashboard.value.id : choice.value;
            const answer = await addTile(props.saving.api.dashboards, dashboardId, insight.value.id);
            if (!answer.ok) {
                errors.value = { general: `The insight was saved, but it was not added to the dashboard. ${answer.errors?.limit ?? answer.message}` };

                return answer;
            }
            dashboard.value ??= dashboards.value.find((item) => item.id === choice.value) ?? { id: choice.value, name: '' };
        }
    }

    return null;
}

const submitLabel = computed(() => (busy.value ? 'Saving…' : partial.value ? 'Retry adding to the dashboard' : props.title));
</script>

<template>
    <ConfirmDialog :open="open" :title="title" @close="close">
        <template #description>The definition of the form as it is now is saved. A relative date range such as "Last 7 days" stays relative.</template>
        <form class="space-y-4" novalidate @submit.prevent="send">
            <template v-if="!partial">
                <TextField v-model="name" label="Name" name="name" autocomplete="off" :error="errors.name" required autofocus />
                <TextAreaField v-model="description" label="Description" name="description" :error="errors.description" />
            </template>

            <div v-if="!partial || choice !== 'none'" class="space-y-1.5">
                <label :for="selectId" class="block text-sm font-medium">Add to dashboard</label>
                <select :id="selectId" v-model="choice" name="dashboard" :disabled="partial" class="block w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-fg" :aria-describedby="`${selectId}-hint`">
                    <option value="none">Do not add to a dashboard</option>
                    <option v-for="item in dashboards" :key="item.id" :value="item.id">{{ item.name }}</option>
                    <option value="new">New dashboard…</option>
                </select>
                <p :id="`${selectId}-hint`" class="text-sm text-muted">{{ dashboardsState === 'failed' ? 'The dashboards could not be loaded. You can still save the insight, or create a new dashboard.' : dashboardsState === 'loading' ? 'Loading your dashboards…' : 'Optional. A tile with this insight is added at the end of the dashboard.' }}</p>
            </div>

            <TextField v-if="choice === 'new' && !partial" v-model="dashboardName" label="New dashboard name" name="dashboard_name" autocomplete="off" :error="errors.dashboardName" required />

            <p v-if="partial" class="text-sm" data-testid="partial-note">Saved as “{{ insight.name }}”.</p>
            <p v-if="errors.general" role="alert" class="rounded-md border border-danger px-3 py-2 text-sm text-danger" data-testid="dialog-error">{{ errors.general }}</p>

            <div class="flex justify-end gap-3">
                <SecondaryButton type="button" @click="close">{{ partial ? 'Close' : 'Cancel' }}</SecondaryButton>
                <PrimaryButton :disabled="busy">{{ submitLabel }}</PrimaryButton>
            </div>
        </form>
    </ConfirmDialog>
</template>
