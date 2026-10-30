<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import { SecondaryButtonClass } from '@/Components/Insights/classes';
import SaveInsightDialog from '@/Components/Insights/SaveInsightDialog.vue';
import { reasonText } from '@/lib/savedInsightState';

// The "Save insight" row under the form of an insight page. A page that was opened from a saved insight (`saved=<uuid>`)
// edits it: it says so, and offers "Update saved insight" (PATCH of the definition) next to "Save as new insight…". Saving is
// offered whenever the form is complete; pressed while it is not, the page says what is missing (`invalid`).
//
// After a save or an update a status sentence (a polite live region that is always in the DOM) says what happened, with links
// to the saved insights and, when it was added to one, the dashboard.
const props = defineProps({
    type: { type: String, required: true },
    // The page's current body, read at the moment of saving.
    query: { type: Function, required: true },
    valid: { type: Boolean, required: true },
    saving: { type: Object, required: true },
    link: { type: Object, required: true },
});

const emit = defineEmits(['invalid']);

const open = ref(false);
const updating = ref(false);
const message = ref('');
const links = ref([]);
const problem = ref('');

const saved = computed(() => props.link.saved.value);
const editing = computed(() => props.link.editing.value);
const broken = computed(() => editing.value && saved.value.state?.status === 'needs_review');

function ask() {
    if (!props.valid) {
        emit('invalid');

        return;
    }
    open.value = true;
}

function onSaved({ insight, dashboard, added }) {
    props.link.adopt(insight);
    problem.value = '';
    message.value = added && dashboard?.name ? `Saved “${insight.name}” and added it to the dashboard “${dashboard.name}”.` : `Saved “${insight.name}”.`;
    links.value = [
        { href: props.saving.pages.saved_insights, text: 'View saved insights' },
        ...(added && dashboard ? [{ href: `${props.saving.pages.dashboards}/${dashboard.id}`, text: dashboard.name ? `Open dashboard ${dashboard.name}` : 'Open the dashboard' }] : []),
    ];
}

async function update() {
    if (updating.value) {
        return;
    }
    if (!props.valid) {
        emit('invalid');

        return;
    }

    updating.value = true;
    problem.value = '';
    const answer = await props.link.update(props.query());
    updating.value = false;

    if (answer.ok) {
        message.value = `Updated “${answer.data.name}”. Its dashboards show the new definition.`;
        links.value = [{ href: props.saving.pages.saved_insights, text: 'View saved insights' }];
    } else {
        message.value = '';
        problem.value = answer.kind === 'validation' ? Object.values(answer.errors).join(' ') || answer.message : answer.message;
    }
}
</script>

<template>
    <div class="space-y-3" data-testid="save-insight">
        <div v-if="link.status.value === 'loading'" class="text-sm text-muted" data-testid="saved-loading">Loading the saved insight…</div>
        <div v-else-if="link.message.value !== ''" class="rounded-md border border-line bg-surface px-3 py-2 text-sm" data-testid="saved-note">{{ link.message.value }}</div>

        <div v-if="editing" class="space-y-2 rounded-md border px-3 py-3" :class="broken ? 'border-warning' : 'border-line bg-surface'" data-testid="saved-editing">
            <p class="text-sm"><span class="font-medium">Editing the saved insight “{{ saved.name }}”.</span> Changes you save replace its definition on every dashboard it is on.</p>
            <p v-if="broken" class="text-sm text-warning" data-testid="saved-needs-review">Needs review. {{ reasonText(saved.state) }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <template v-if="editing">
                <button type="button" class="inline-flex items-center justify-center rounded-md bg-accent px-4 py-2 font-medium text-accent-fg hover:bg-accent-hover aria-disabled:cursor-not-allowed aria-disabled:opacity-60" :aria-disabled="updating || !valid ? 'true' : undefined" @click="update">{{ updating ? 'Updating…' : 'Update saved insight' }}</button>
                <button type="button" :class="SecondaryButtonClass" aria-haspopup="dialog" :aria-disabled="valid ? undefined : 'true'" class="aria-disabled:cursor-not-allowed aria-disabled:opacity-60" @click="ask">Save as new insight…</button>
            </template>
            <button v-else type="button" :class="SecondaryButtonClass" aria-haspopup="dialog" :aria-disabled="valid ? undefined : 'true'" :aria-describedby="valid ? undefined : 'save-hint'" class="aria-disabled:cursor-not-allowed aria-disabled:opacity-60" @click="ask">Save insight…</button>
            <p v-if="!valid" id="save-hint" class="text-sm text-muted">Complete the form to save it.</p>
        </div>

        <p v-if="problem" role="alert" class="rounded-md border border-danger px-3 py-2 text-sm text-danger" data-testid="save-problem">{{ problem }}</p>

        <!-- Always in the DOM: a live region only announces text that is inserted into one that already exists. -->
        <div role="status" aria-live="polite" aria-atomic="true" data-testid="save-status">
            <p v-if="message" class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-line bg-surface px-3 py-2 text-sm">
                <span>{{ message }}</span>
                <Link v-for="item in links" :key="item.href" :href="item.href" class="text-accent hover:text-accent-hover">{{ item.text }}</Link>
            </p>
        </div>
    </div>

    <!-- Mounted only while it is open: a closed dialog is still in the DOM, and its labels would be found by anything that looks for a field on the page. -->
    <SaveInsightDialog v-if="open" :open="open" :type="type" :query="query" :saving="saving" :suggested-name="editing ? saved.name : ''" :title="editing ? 'Save as new insight' : 'Save insight'" @close="open = false" @saved="onSaved" />
</template>
