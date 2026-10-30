<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

import DeleteDialog from '@/Components/Dashboards/DeleteDialog.vue';
import LabelDialog from '@/Components/Dashboards/LabelDialog.vue';
import Pager from '@/Components/Dashboards/Pager.vue';
import RelativeTime from '@/Components/Dashboards/RelativeTime.vue';
import StateBadge from '@/Components/Dashboards/StateBadge.vue';
import TypeBadge from '@/Components/Dashboards/TypeBadge.vue';
import { SecondaryButtonClass, SmallButtonClass } from '@/Components/Insights/classes';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useNow } from '@/composables/useNow';
import { deleteSavedInsight, listAll, updateSavedInsight } from '@/lib/dashboardsApi';
import { filterSavedInsights } from '@/lib/filterInsights';
import { paginate } from '@/lib/pagination';
import { TYPE_LABELS, canRepair, needsReview, reasonText } from '@/lib/savedInsightState';
import { withSaved } from '@/lib/savedInsightLinks';

// The saved insights of a project: a table to open, rename and delete them. The page loads every saved insight (at most
// `limits.max_saved_insights`, one or two API calls), filters by type and text and pages the result here. "Open" goes to the
// page of the insight's type with `saved=<uuid>`: that page loads the stored definition and fills its form with it. An
// insight that needs review says why and opens the same way, to be repaired and saved again.
const props = defineProps({
    project: { type: Object, required: true },
    links: { type: Object, required: true },
    limits: { type: Object, required: true },
});

const now = useNow();
const items = ref([]);
const state = ref('loading');
const failure = ref('');
const page = ref(1);
const type = ref('');
const search = ref('');
const status = ref('');
const heading = ref(null);

const filtered = computed(() => filterSavedInsights(items.value, { type: type.value, search: search.value }));
const paging = computed(() => paginate(filtered.value, page.value, props.limits.page_size));

watch([type, search], () => (page.value = 1));

const openUrl = (insight) => `${props.links.pages[insight.type]}?${withSaved('', insight.id)}`;

async function load() {
    state.value = 'loading';
    const answer = await listAll(props.links.api.saved_insights);
    if (answer.ok) {
        items.value = answer.data;
        state.value = 'ready';
    } else {
        failure.value = answer.message;
        state.value = 'failed';
    }
}

onMounted(load);

const editing = ref(null);
const rename = (fields) => updateSavedInsight(props.links.api.saved_insights, editing.value.id, { name: fields.name, description: fields.description === '' ? null : fields.description });

function renamed(updated) {
    items.value = items.value.map((item) => (item.id === updated.id ? { ...item, name: updated.name, description: updated.description, updated_at: updated.updated_at } : item));
    status.value = `Renamed the saved insight to ${updated.name}.`;
}

const removing = ref(null);
const destroy = () => deleteSavedInsight(props.links.api.saved_insights, removing.value.id);

async function deleted() {
    const { id, name } = removing.value;
    items.value = items.value.filter((item) => item.id !== id);
    status.value = `Deleted the saved insight ${name}.`;
    await nextTick();
    heading.value?.focus();
}

const typeOptions = [{ value: '', label: 'All types' }, ...Object.entries(TYPE_LABELS).map(([value, label]) => ({ value, label }))];
const fieldClass = 'block w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-fg';
</script>

<template>
    <Head :title="`Saved insights - ${project.name}`" />

    <div class="space-y-6">
        <header class="space-y-1">
            <p class="text-sm"><Link :href="links.pages.project" class="text-accent hover:text-accent-hover">{{ project.name }}</Link></p>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 ref="heading" tabindex="-1" class="text-2xl font-semibold tracking-tight focus:outline-none">Saved insights</h1>
                <Link :href="links.pages.dashboards" :class="SecondaryButtonClass">Dashboards</Link>
            </div>
            <p class="text-sm text-muted">Insights you saved from Trends, Funnel or Retention. {{ items.length }} of {{ limits.max_saved_insights }} saved insights.</p>
        </header>

        <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-testid="status">{{ status }}</div>

        <form v-if="items.length > 0" role="search" aria-label="Filter saved insights" class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_12rem]" @submit.prevent>
            <div class="space-y-1.5">
                <label for="saved-search" class="block text-sm font-medium">Search</label>
                <input id="saved-search" v-model="search" type="search" name="search" autocomplete="off" :class="fieldClass" />
            </div>
            <div class="space-y-1.5">
                <label for="saved-type" class="block text-sm font-medium">Type</label>
                <select id="saved-type" v-model="type" name="type" :class="fieldClass">
                    <option v-for="option in typeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </div>
        </form>

        <section aria-label="Saved insights" :aria-busy="state === 'loading' ? 'true' : 'false'" class="space-y-4">
            <div v-if="state === 'loading'" class="space-y-2" aria-hidden="true" data-testid="state-loading">
                <div v-for="n in 4" :key="n" class="h-12 animate-pulse rounded-md bg-line"></div>
            </div>

            <div v-else-if="state === 'failed'" role="alert" class="space-y-3 rounded-md border border-danger px-4 py-6" data-testid="state-failed">
                <p class="font-medium text-danger">{{ failure }}</p>
                <SecondaryButton type="button" @click="load">Try again</SecondaryButton>
            </div>

            <div v-else-if="items.length === 0" class="space-y-2 rounded-md border border-dashed border-line-strong px-4 py-10 text-center" data-testid="state-empty">
                <p class="font-medium">No saved insights yet</p>
                <p class="text-sm text-muted">Build a trend, a funnel or a retention table and choose Save insight. It then shows up here and can be added to a dashboard.</p>
                <div class="flex flex-wrap justify-center gap-3 pt-2">
                    <Link :href="links.pages.trends" :class="SecondaryButtonClass">Open Trends</Link>
                    <Link :href="links.pages.funnel" :class="SecondaryButtonClass">Open Funnel</Link>
                    <Link :href="links.pages.retention" :class="SecondaryButtonClass">Open Retention</Link>
                </div>
            </div>

            <div v-else-if="filtered.length === 0" class="rounded-md border border-line bg-surface px-4 py-10 text-center" data-testid="state-no-match">
                <p class="font-medium">No saved insight matches</p>
                <p class="text-sm text-muted">Change the search or the type.</p>
            </div>

            <template v-else>
                <div role="group" aria-label="Saved insights, table" tabindex="0" class="relative overflow-x-auto rounded-md border border-line">
                    <table class="w-full min-w-[44rem] border-collapse text-sm" data-testid="saved-insights-table">
                        <caption class="sr-only">Saved insights</caption>
                        <thead class="bg-surface">
                            <tr class="border-b border-line-strong">
                                <th scope="col" class="px-3 py-2 text-left font-medium">Name</th>
                                <th scope="col" class="px-3 py-2 text-left font-medium">Type</th>
                                <th scope="col" class="px-3 py-2 text-left font-medium">State</th>
                                <th scope="col" class="px-3 py-2 text-left font-medium">Updated</th>
                                <th scope="col" class="px-3 py-2 text-left font-medium">Created by</th>
                                <th scope="col" class="px-3 py-2 text-left font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="insight in paging.items" :key="insight.id" class="border-b border-line align-top" data-testid="saved-insight-row">
                                <th scope="row" class="max-w-[18rem] px-3 py-2 text-left font-normal">
                                    <span class="block font-medium break-words">{{ insight.name }}</span>
                                    <span v-if="insight.description" class="block text-muted break-words">{{ insight.description }}</span>
                                    <span v-if="needsReview(insight)" class="mt-1 block text-warning" data-testid="review-reason">{{ reasonText(insight.state) }}</span>
                                </th>
                                <td class="px-3 py-2"><TypeBadge :type="insight.type" /></td>
                                <td class="px-3 py-2"><StateBadge :insight="insight" /></td>
                                <td class="whitespace-nowrap px-3 py-2"><RelativeTime :at="insight.updated_at" :now="now" /></td>
                                <td class="px-3 py-2">{{ insight.created_by ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap gap-2">
                                        <Link v-if="canRepair(insight)" :href="openUrl(insight)" :class="SmallButtonClass" :aria-label="`${needsReview(insight) ? 'Open to repair' : 'Open'} ${insight.name}`">{{ needsReview(insight) ? 'Open to repair' : 'Open' }}</Link>
                                        <button type="button" :class="SmallButtonClass" :aria-label="`Rename ${insight.name}`" aria-haspopup="dialog" @click="editing = insight">Rename</button>
                                        <button type="button" :class="SmallButtonClass" :aria-label="`Delete ${insight.name}`" aria-haspopup="dialog" @click="removing = insight">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pager :paging="paging" noun="saved insights" @page="page = $event" />
            </template>
        </section>
    </div>

    <LabelDialog v-if="editing" :open="editing !== null" title="Rename saved insight" submit-label="Save" :initial="{ name: editing.name, description: editing.description ?? '' }" :limits="links.limits" :submit="rename" @close="editing = null" @done="renamed" />
    <DeleteDialog v-if="removing" :open="removing !== null" title="Delete this saved insight?" confirm-label="Delete saved insight" :confirm="destroy" @close="removing = null" @done="deleted">
        <strong class="text-fg">{{ removing.name }}</strong> is deleted, and removed from every dashboard it is on.
    </DeleteDialog>
</template>
