<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';

import Pager from '@/Components/Dashboards/Pager.vue';
import DeleteDialog from '@/Components/Dashboards/DeleteDialog.vue';
import LabelDialog from '@/Components/Dashboards/LabelDialog.vue';
import RelativeTime from '@/Components/Dashboards/RelativeTime.vue';
import { SecondaryButtonClass, SmallButtonClass } from '@/Components/Insights/classes';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useNow } from '@/composables/useNow';
import { createDashboard, deleteDashboard, listAll, updateDashboard } from '@/lib/dashboardsApi';
import { paginate } from '@/lib/pagination';

// The dashboards of a project: cards with the name, the description, how many tiles, when it was last opened and who made it.
// The page reads and writes through the JSON API (the same session): it loads every dashboard (a project has at most
// `limits.max_dashboards`) and pages the list here. Creating one opens it, so the first thing to do with it (adding tiles) is next.
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
const status = ref('');
const heading = ref(null);

const paging = computed(() => paginate(items.value, page.value, props.limits.page_size));
const atLimit = computed(() => items.value.length >= props.limits.max_dashboards);
const showUrl = (dashboard) => `${props.links.pages.dashboards}/${dashboard.id}`;

async function load() {
    state.value = 'loading';
    const answer = await listAll(props.links.api.dashboards);
    if (answer.ok) {
        items.value = answer.data;
        state.value = 'ready';
    } else {
        failure.value = answer.message;
        state.value = 'failed';
    }
}

onMounted(load);

// ------------------------------------------------------------ create
const createOpen = ref(false);

const create = (fields) => createDashboard(props.links.api.dashboards, { name: fields.name, ...(fields.description === '' ? {} : { description: fields.description }) });

function created(dashboard) {
    router.visit(showUrl(dashboard));
}

// ------------------------------------------------------------ rename
const editing = ref(null);

const rename = (fields) => updateDashboard(props.links.api.dashboards, editing.value.id, { name: fields.name, description: fields.description === '' ? null : fields.description });

function renamed(updated) {
    items.value = items.value.map((item) => (item.id === updated.id ? { ...item, name: updated.name, description: updated.description, updated_at: updated.updated_at } : item));
    status.value = `Renamed the dashboard to ${updated.name}.`;
}

// ------------------------------------------------------------ delete
const removing = ref(null);

const destroy = () => deleteDashboard(props.links.api.dashboards, removing.value.id);

async function deleted() {
    const name = removing.value.name;
    items.value = items.value.filter((item) => item.id !== removing.value.id);
    status.value = `Deleted the dashboard ${name}.`;
    await nextTick();
    heading.value?.focus();
}
</script>

<template>
    <Head :title="`Dashboards - ${project.name}`" />

    <div class="space-y-6">
        <header class="space-y-1">
            <p class="text-sm"><Link :href="links.pages.project" class="text-accent hover:text-accent-hover">{{ project.name }}</Link></p>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 ref="heading" tabindex="-1" class="text-2xl font-semibold tracking-tight focus:outline-none">Dashboards</h1>
                <div class="flex flex-wrap items-center gap-3">
                    <Link :href="links.pages.saved_insights" :class="SecondaryButtonClass">Saved insights</Link>
                    <PrimaryButton type="button" aria-haspopup="dialog" :disabled="atLimit" @click="!atLimit && (createOpen = true)">New dashboard</PrimaryButton>
                </div>
            </div>
            <p class="text-sm text-muted">Saved insights side by side, kept fresh in the background. {{ items.length }} of {{ limits.max_dashboards }} dashboards.</p>
            <p v-if="atLimit" class="text-sm text-muted" data-testid="limit-note">This project has reached its limit of {{ limits.max_dashboards }} dashboards. Delete one to create another.</p>
        </header>

        <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-testid="status">{{ status }}</div>

        <section aria-label="Dashboards" :aria-busy="state === 'loading' ? 'true' : 'false'">
            <div v-if="state === 'loading'" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-hidden="true" data-testid="state-loading">
                <div v-for="n in 3" :key="n" class="h-40 animate-pulse rounded-md bg-line"></div>
            </div>

            <div v-else-if="state === 'failed'" role="alert" class="space-y-3 rounded-md border border-danger px-4 py-6" data-testid="state-failed">
                <p class="font-medium text-danger">{{ failure }}</p>
                <SecondaryButton type="button" @click="load">Try again</SecondaryButton>
            </div>

            <div v-else-if="items.length === 0" class="space-y-3 rounded-md border border-dashed border-line-strong px-4 py-10 text-center" data-testid="state-empty">
                <p class="font-medium">No dashboards yet</p>
                <p class="text-sm text-muted">A dashboard puts saved insights next to each other. Create one, then add the insights you have saved from Trends, Funnel or Retention.</p>
                <PrimaryButton type="button" aria-haspopup="dialog" @click="createOpen = true">New dashboard</PrimaryButton>
            </div>

            <div v-else class="space-y-4">
                <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="dashboard-list">
                    <li v-for="dashboard in paging.items" :key="dashboard.id" class="flex flex-col justify-between gap-4 rounded-md border border-line bg-surface p-4" data-testid="dashboard-card">
                        <div class="min-w-0 space-y-1">
                            <h2 class="text-lg font-semibold tracking-tight break-words">
                                <Link :href="showUrl(dashboard)" class="text-accent hover:text-accent-hover">{{ dashboard.name }}</Link>
                            </h2>
                            <p v-if="dashboard.description" class="line-clamp-3 text-sm text-muted break-words">{{ dashboard.description }}</p>
                            <dl class="space-y-0.5 pt-1 text-sm text-muted">
                                <div class="flex gap-1"><dt class="sr-only">Tiles</dt><dd data-testid="tile-count">{{ dashboard.tiles_count }} {{ dashboard.tiles_count === 1 ? 'tile' : 'tiles' }}</dd></div>
                                <div class="flex gap-1"><dt>Last viewed</dt><dd><RelativeTime v-if="dashboard.last_viewed_at" :at="dashboard.last_viewed_at" :now="now" /><template v-else>never</template></dd></div>
                                <div v-if="dashboard.created_by" class="flex gap-1"><dt>Created by</dt><dd>{{ dashboard.created_by }}</dd></div>
                            </dl>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" :class="SmallButtonClass" :aria-label="`Rename ${dashboard.name}`" aria-haspopup="dialog" @click="editing = dashboard">Rename</button>
                            <button type="button" :class="SmallButtonClass" :aria-label="`Delete ${dashboard.name}`" aria-haspopup="dialog" @click="removing = dashboard">Delete</button>
                        </div>
                    </li>
                </ul>
                <Pager :paging="paging" noun="dashboards" @page="page = $event" />
            </div>
        </section>
    </div>

    <LabelDialog :open="createOpen" title="New dashboard" submit-label="Create dashboard" :limits="links.limits" :submit="create" @close="createOpen = false" @done="created" />
    <LabelDialog v-if="editing" :open="editing !== null" title="Rename dashboard" submit-label="Save" :initial="{ name: editing.name, description: editing.description ?? '' }" :limits="links.limits" :submit="rename" @close="editing = null" @done="renamed" />
    <DeleteDialog v-if="removing" :open="removing !== null" title="Delete this dashboard?" confirm-label="Delete dashboard" :confirm="destroy" @close="removing = null" @done="deleted">
        <strong class="text-fg">{{ removing.name }}</strong> and its tiles are deleted. The saved insights on it stay.
    </DeleteDialog>
</template>
