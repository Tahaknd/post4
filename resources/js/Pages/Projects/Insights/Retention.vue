<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, reactive, ref, useId, watch } from 'vue';

import DateRangeControl from '@/Components/Insights/DateRangeControl.vue';
import OptionSelect from '@/Components/Insights/OptionSelect.vue';
import PeriodsControl from '@/Components/Insights/PeriodsControl.vue';
import RetentionChart from '@/Components/Insights/RetentionChart.vue';
import RetentionEvent from '@/Components/Insights/RetentionEvent.vue';
import RunControls from '@/Components/Insights/RunControls.vue';
import RunResults from '@/Components/Insights/RunResults.vue';
import { useEventNames } from '@/composables/useEventNames';
import { useRunPage } from '@/composables/useRunPage';
import { useTouchedFields } from '@/composables/useTouchedFields';
import { useUrlState } from '@/composables/useUrlState';
import { ROLES, conditionEvent, copyCondition, decodeDefinition, defaultDefinition, encodeDefinition, mapServerErrors, toRequestBody, validateDefinition } from '@/lib/retentionDefinition';
import { describeSummary, failureHelp, isEmptyRetention, isRetentionResult, periodNoun, typeHelp } from '@/lib/retentionFormat';

// The Retention page. Everything it needs to build a definition (allowed values, limits, defaults) and the API URLs come from
// the server as props; there is no API key, the API is called with the session. The definition lives in one reactive object
// that mirrors the API body, is validated on every change (instant feedback, the same limits as the server) and is mirrored
// into the query string, so a link reproduces the table. The query string is NEVER trusted: decodeDefinition() checks every
// field against the props.
//
// Like the funnel, retention runs on a button, not on every change: it follows every person through every period, and its
// two events are chosen one at a time. A link that carries a complete definition runs once when it is opened. While it runs there
// is a Cancel button; the result stays on screen, marked as out of date, when the form changes after it.
//
// An unfinished field is not an error until the user has left it (or tried to run, which counts as having left every field there
// is): its message then appears, and the Run button, which is aria-disabled and never natively disabled, moves focus to the first
// problem instead of doing nothing.
const props = defineProps({
    project: { type: Object, required: true },
    options: { type: Object, required: true },
    defaults: { type: Object, required: true },
    limits: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const fromLink = decodeDefinition(window.location.search, props);
const definition = reactive(fromLink ?? defaultDefinition(props));

// One list of suggestions for both events: one cache and one request budget.
const names = useEventNames(props.urls.events, props.limits.max_search_length);

const validation = computed(() => validateDefinition(definition, props));
const valid = computed(() => validation.value.ok);
const body = computed(() => toRequestBody(definition, props));

useUrlState(() => encodeDefinition(definition, props));

const { touched, onFocusOut } = useTouchedFields();
// The events a refused Run has reported on. An event that is still EMPTY is unfinished, not wrong: leaving it says nothing (a
// message appearing under a field the user just left would also shift the button they are about to click), only a refused Run
// names it.
const refused = reactive(new Set());

const form = ref(null);

// While the return event is the cohort event it has no form of its own. Turning that off starts from a copy of the cohort event,
// so what was typed is not lost and the two can be told apart.
watch(
    () => definition.same,
    (same) => {
        if (!same) {
            definition.returning = copyCondition(definition.target);
        }
    },
);

// ---------------------------------------------------------------- running
function refuse() {
    // Every field there is now has been "left": its message may appear.
    for (const role of ROLES) {
        touched.add(role);
        refused.add(role);
        definition[role].filters.forEach((row) => touched.add(`filter:${row.id}`));
    }
    ['periods', 'date.from', 'date.to'].forEach((field) => touched.add(field));
    nextTick(() => form.value?.querySelector('[aria-invalid="true"]')?.focus());
}

const { state, busy, attempted, runButton, shown, view, error, help, stale, stageText, status, start, cancel } = useRunPage({
    postUrl: props.urls.retention,
    runsUrl: props.urls.runs,
    body,
    valid,
    isResult: isRetentionResult,
    isEmpty: isEmptyRetention,
    refuse,
    failureHelp,
    messages: {
        running: 'Running retention…',
        refused: 'Fix the highlighted fields to run retention.',
        empty: 'Nobody did the cohort event in this range.',
        describe: describeSummary,
    },
});

onMounted(() => {
    if (fromLink !== null && valid.value) {
        start();
    }
});

const IDLE_COPY = { title: 'Build a retention table to see who comes back', text: 'Choose the cohort event and the return event above, then run retention. The table and the curve appear here.' };
const emptyCopy = computed(() => {
    // the event of the result on screen (the form may have changed since): it is in the body the run was asked for
    const asked = shown.value === null ? null : JSON.parse(shown.value.key).target.event;

    return { title: 'Nobody did the cohort event in this range', text: `No one did ${asked ?? 'the cohort event'}${asked === null ? '' : ' (with its filters)'} in these dates. Try a longer date range or check the cohort event.` };
});

// ---------------------------------------------------------------- when a field's message may appear
// The instant client-side messages win but show only for fields the user has been through (or after a refused Run); the
// API's 422 messages fill the rest and always show (the user has asked for exactly that definition).
const serverErrors = computed(() => (state.value.phase === 'invalid' ? mapServerErrors(state.value.fieldErrors, definition) : null));

function hasFieldErrors(mapped) {
    return Object.keys(mapped).some((key) => key !== 'general');
}

const errors = computed(() => {
    const client = validation.value.errors;
    const server = serverErrors.value;
    const seen = (field) => touched.has(field);

    const roles = {};
    for (const role of definition.same ? ['target'] : ROLES) {
        const mine = client[role];
        const theirs = server?.[role];
        const entry = {};

        const showEvent = conditionEvent(definition[role]) === '' ? refused.has(role) : seen(role);
        const event = (mine?.event !== undefined && showEvent ? mine.event : undefined) ?? theirs?.event;
        if (event !== undefined) {
            entry.event = event;
        }

        const filters = { ...(theirs?.filters ?? {}) };
        for (const [id, row] of Object.entries(mine?.filters ?? {})) {
            if (seen(`filter:${id}`)) {
                filters[id] = row;
            }
        }
        if (Object.keys(filters).length > 0) {
            entry.filters = filters;
        }

        // too many filters is a consequence of the last click, not an unfinished field
        const general = mine?.general ?? theirs?.general;
        if (general !== undefined) {
            entry.general = general;
        }
        if (Object.keys(entry).length > 0) {
            roles[role] = entry;
        }
    }

    return {
        roles,
        period: server?.period,
        periods: (client.periods !== undefined && seen('periods') ? client.periods : undefined) ?? server?.periods,
        type: server?.type,
        timezone: server?.timezone,
        date: (client.date !== undefined && touched.has('date.from') && touched.has('date.to') ? client.date : undefined) ?? server?.date,
        general: server?.general ?? (server !== null && !hasFieldErrors(server) ? state.value.error?.message : undefined),
    };
});

// ---------------------------------------------------------------- the words around the controls
const timezones = computed(() => props.options.timezones.map((zone) => ({ value: zone, label: zone })));
const noun = computed(() => periodNoun(definition.period));
const typeText = computed(() => typeHelp(definition.type));
const periodsHint = computed(() => `${props.limits.min_periods} to ${props.limits.max_periods}: how many ${noun.value}s after the cohort ${noun.value} each cohort is followed.`);
const periodHint = computed(() => (definition.period === 'week' ? 'A week starts on Monday.' : `Each row is one ${noun.value}: the people who first did the cohort event in it.`));

const sameId = useId();
</script>

<template>
    <Head :title="`Retention - ${project.name}`" />

    <div class="space-y-8">
        <header class="space-y-1">
            <p class="text-sm"><Link :href="urls.project" class="text-accent hover:text-accent-hover">{{ project.name }}</Link></p>
            <h1 class="text-2xl font-semibold tracking-tight">Retention</h1>
            <p class="text-sm text-muted">Of the people who do something, how many come back. Periods follow the time zone chosen below; the project’s is {{ project.timezone }}.</p>
        </header>

        <form ref="form" aria-label="Retention definition" class="space-y-6 rounded-md border border-line bg-surface p-4 sm:p-5" novalidate @submit.prevent="start" @focusout="onFocusOut">
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <RetentionEvent
                    v-model="definition.target"
                    heading="People first do…"
                    help="The event that puts a person into a cohort. People are grouped by the period in which they did it."
                    label="Cohort event"
                    scope="cohort event"
                    field="target"
                    :names="names"
                    :operators="options.operators"
                    :limits="limits"
                    :errors="errors.roles.target"
                />

                <div class="space-y-3">
                    <RetentionEvent
                        v-if="!definition.same"
                        v-model="definition.returning"
                        heading="…and come back to do"
                        help="The event that counts as coming back, a number of periods later."
                        label="Return event"
                        scope="return event"
                        field="returning"
                        :names="names"
                        :operators="options.operators"
                        :limits="limits"
                        :errors="errors.roles.returning"
                    />
                    <section v-else aria-labelledby="retention-returning-heading" class="space-y-1 rounded-md border border-line bg-canvas p-3 sm:p-4">
                        <h2 id="retention-returning-heading" class="text-sm font-medium">…and come back to do</h2>
                        <p class="text-sm text-muted" data-testid="same-note">The same event as the cohort event, with its filters.</p>
                    </section>

                    <div class="flex items-start gap-2">
                        <input :id="sameId" v-model="definition.same" type="checkbox" name="same" class="mt-1 size-4 shrink-0" :aria-describedby="`${sameId}-hint`" />
                        <div class="space-y-0.5">
                            <label :for="sameId" class="block text-sm font-medium">Same event</label>
                            <p :id="`${sameId}-hint`" class="text-sm text-muted">People come back by doing the cohort event again.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <OptionSelect v-model="definition.period" label="Period" name="period" :options="options.periods" :hint="periodHint" :error="errors.period" />
                <PeriodsControl v-model="definition.periods" :hint="periodsHint" :error="errors.periods" />
                <OptionSelect v-model="definition.type" label="Retention type" name="retention_type" :options="options.retention_types" :hint="typeText" :error="errors.type" />
                <OptionSelect
                    v-model="definition.timezone"
                    label="Time zone"
                    name="timezone"
                    :options="timezones"
                    :hint="`Where days, weeks and months begin. The project’s zone is ${project.timezone}.`"
                    :error="errors.timezone"
                />
                <DateRangeControl v-model="definition.range" :presets="options.presets" :timezone="definition.timezone" :error="errors.date" />
            </div>

            <p v-if="errors.general" role="alert" class="rounded-md border border-danger px-3 py-2 text-sm text-danger" data-testid="general-error">{{ errors.general }}</p>

            <RunControls ref="runButton" label="Run retention" :busy="busy" :valid="valid" :attempted="attempted" :stage-text="stageText" hint="Fix the highlighted fields to run retention." @cancel="cancel" />
        </form>

        <RunResults :view="view" :status="status" :busy="busy" :stale="stale" noun="retention" :error="error" :help="help" :idle-copy="IDLE_COPY" :empty-copy="emptyCopy" @retry="start">
            <RetentionChart :result="shown.result" :stale="stale" />
        </RunResults>
    </div>
</template>
