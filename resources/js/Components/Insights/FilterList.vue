<script setup>
import { useId } from 'vue';

import { newFilter } from '@/lib/insightQuery';

// Property filters: up to `limits.max_filters` rows of { key, operator, value }. The operators come from the server;
// the value is disabled (and not sent) for an operator that takes none ("is set"). Rows are labelled for screen
// readers by their position because the visible layout is a single line of controls. A page with several lists of filters
// (a funnel has one per step) names each one: `scope` ("step 2") is added to the screen reader names of the legend, the
// rows and the buttons, and `emptyText` says what an empty list means there.
const props = defineProps({
    operators: { type: Array, required: true },
    limits: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    scope: { type: String, default: '' },
    emptyText: { type: String, default: 'No filters: every event counts. Filters match event properties.' },
});

// "Filter 2", or "Step 2 filter 2" inside a scope.
const itemName = (index) => (props.scope === '' ? `Filter ${index + 1}` : `${props.scope[0].toUpperCase()}${props.scope.slice(1)} filter ${index + 1}`);

const filters = defineModel({ type: Array, required: true });

// Element ids come from useId() (unique per component instance and stable across server and client), prefixed with the
// row's own id: `data-field` marks each input for the page's "was it touched" tracking.
const uid = useId();
const rowId = (filter, part) => `${uid}-${filter.id}-${part}`;

function add() {
    if (filters.value.length < props.limits.max_filters) {
        filters.value = [...filters.value, newFilter()];
    }
}

function remove(id) {
    filters.value = filters.value.filter((filter) => filter.id !== id);
}

function patch(id, changes) {
    filters.value = filters.value.map((filter) => (filter.id === id ? { ...filter, ...changes } : filter));
}

const needsValue = (operator) => props.operators.find((item) => item.value === operator)?.needs_value !== false;
</script>

<template>
    <fieldset class="space-y-2">
        <legend class="block text-sm font-medium">Filters<span v-if="scope" class="sr-only"> for {{ scope }}</span></legend>

        <p v-if="filters.length === 0" class="text-sm text-muted">{{ emptyText }}</p>

        <ul v-else class="space-y-3">
            <li v-for="(filter, index) in filters" :key="filter.id" class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_10rem_minmax(0,1fr)_auto] sm:items-start">
                <div>
                    <label :for="rowId(filter, 'key')" class="sr-only">{{ itemName(index) }} property key</label>
                    <input
                        :id="rowId(filter, 'key')"
                        :data-field="`filter:${filter.id}`"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="Property key"
                        :value="filter.key"
                        :maxlength="limits.max_filter_key_length"
                        :aria-invalid="errors[filter.id]?.key ? 'true' : undefined"
                        :aria-describedby="errors[filter.id]?.key ? rowId(filter, 'key-error') : undefined"
                        class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
                        :class="errors[filter.id]?.key ? 'border-danger' : 'border-line-strong'"
                        @input="patch(filter.id, { key: $event.target.value })"
                    />
                    <p v-if="errors[filter.id]?.key" :id="rowId(filter, 'key-error')" role="alert" class="mt-1 text-sm text-danger">{{ errors[filter.id].key }}</p>
                </div>

                <div>
                    <label :for="rowId(filter, 'op')" class="sr-only">{{ itemName(index) }} operator</label>
                    <select
                        :id="rowId(filter, 'op')"
                        :value="filter.operator"
                        class="block w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-fg"
                        @change="patch(filter.id, { operator: $event.target.value })"
                    >
                        <option v-for="operator in operators" :key="operator.value" :value="operator.value">{{ operator.label }}</option>
                    </select>
                </div>

                <div>
                    <label :for="rowId(filter, 'value')" class="sr-only">{{ itemName(index) }} value</label>
                    <input
                        :id="rowId(filter, 'value')"
                        :data-field="`filter:${filter.id}`"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="needsValue(filter.operator) ? 'Value' : 'No value needed'"
                        :value="needsValue(filter.operator) ? filter.value : ''"
                        :disabled="!needsValue(filter.operator)"
                        :maxlength="limits.max_filter_value_length"
                        :aria-invalid="errors[filter.id]?.value ? 'true' : undefined"
                        :aria-describedby="errors[filter.id]?.value ? rowId(filter, 'value-error') : undefined"
                        class="block w-full rounded-md border bg-surface px-3 py-2 text-fg disabled:cursor-not-allowed disabled:opacity-60"
                        :class="errors[filter.id]?.value ? 'border-danger' : 'border-line-strong'"
                        @input="patch(filter.id, { value: $event.target.value })"
                    />
                    <p v-if="errors[filter.id]?.value" :id="rowId(filter, 'value-error')" role="alert" class="mt-1 text-sm text-danger">{{ errors[filter.id].value }}</p>
                </div>

                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-line-strong bg-surface px-3 py-2 text-fg hover:bg-canvas"
                    @click="remove(filter.id)"
                >
                    Remove<span class="sr-only"> {{ itemName(index).toLowerCase() }}</span>
                </button>
            </li>
        </ul>

        <button
            type="button"
            class="inline-flex items-center justify-center rounded-md border border-line-strong bg-surface px-3 py-2 text-sm font-medium text-fg hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
            :aria-disabled="filters.length >= limits.max_filters ? 'true' : undefined"
            @click="add"
        >
            Add filter<span v-if="scope" class="sr-only"> to {{ scope }}</span>
        </button>
        <p v-if="filters.length >= limits.max_filters" class="text-sm text-muted">At most {{ limits.max_filters }} filters.</p>
    </fieldset>
</template>
