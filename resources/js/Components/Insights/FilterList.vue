<script setup>
import { newFilter } from '@/lib/trendsDefinition';

// Property filters: up to `limits.max_filters` rows of { key, operator, value }. The operators come from the server;
// the value is disabled (and not sent) for an operator that takes none ("is set"). Rows are labelled for screen
// readers by their position because the visible layout is a single line of controls.
const props = defineProps({
    operators: { type: Array, required: true },
    limits: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
});

const filters = defineModel({ type: Array, required: true });

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
        <legend class="block text-sm font-medium">Filters</legend>

        <p v-if="filters.length === 0" class="text-sm text-muted">No filters: every event counts. Filters match event properties.</p>

        <ul v-else class="space-y-3">
            <li v-for="(filter, index) in filters" :key="filter.id" class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_10rem_minmax(0,1fr)_auto] sm:items-start">
                <div>
                    <label :for="`f-${filter.id}-key`" class="sr-only">Filter {{ index + 1 }} property key</label>
                    <input
                        :id="`f-${filter.id}-key`"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="Property key"
                        :value="filter.key"
                        :maxlength="limits.max_filter_key_length"
                        :aria-invalid="errors[filter.id]?.key ? 'true' : undefined"
                        :aria-describedby="errors[filter.id]?.key ? `f-${filter.id}-key-error` : undefined"
                        class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
                        :class="errors[filter.id]?.key ? 'border-danger' : 'border-line-strong'"
                        @input="patch(filter.id, { key: $event.target.value })"
                    />
                    <p v-if="errors[filter.id]?.key" :id="`f-${filter.id}-key-error`" role="alert" class="mt-1 text-sm text-danger">{{ errors[filter.id].key }}</p>
                </div>

                <div>
                    <label :for="`f-${filter.id}-op`" class="sr-only">Filter {{ index + 1 }} operator</label>
                    <select
                        :id="`f-${filter.id}-op`"
                        :value="filter.operator"
                        class="block w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-fg"
                        @change="patch(filter.id, { operator: $event.target.value })"
                    >
                        <option v-for="operator in operators" :key="operator.value" :value="operator.value">{{ operator.label }}</option>
                    </select>
                </div>

                <div>
                    <label :for="`f-${filter.id}-value`" class="sr-only">Filter {{ index + 1 }} value</label>
                    <input
                        :id="`f-${filter.id}-value`"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="needsValue(filter.operator) ? 'Value' : 'No value needed'"
                        :value="needsValue(filter.operator) ? filter.value : ''"
                        :disabled="!needsValue(filter.operator)"
                        :maxlength="limits.max_filter_value_length"
                        :aria-invalid="errors[filter.id]?.value ? 'true' : undefined"
                        :aria-describedby="errors[filter.id]?.value ? `f-${filter.id}-value-error` : undefined"
                        class="block w-full rounded-md border bg-surface px-3 py-2 text-fg disabled:cursor-not-allowed disabled:opacity-60"
                        :class="errors[filter.id]?.value ? 'border-danger' : 'border-line-strong'"
                        @input="patch(filter.id, { value: $event.target.value })"
                    />
                    <p v-if="errors[filter.id]?.value" :id="`f-${filter.id}-value-error`" role="alert" class="mt-1 text-sm text-danger">{{ errors[filter.id].value }}</p>
                </div>

                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-line-strong bg-surface px-3 py-2 text-fg hover:bg-canvas"
                    @click="remove(filter.id)"
                >
                    Remove<span class="sr-only"> filter {{ index + 1 }}</span>
                </button>
            </li>
        </ul>

        <button
            type="button"
            class="inline-flex items-center justify-center rounded-md border border-line-strong bg-surface px-3 py-2 text-sm font-medium text-fg hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
            :aria-disabled="filters.length >= limits.max_filters ? 'true' : undefined"
            @click="add"
        >
            Add filter
        </button>
        <p v-if="filters.length >= limits.max_filters" class="text-sm text-muted">At most {{ limits.max_filters }} filters.</p>
    </fieldset>
</template>
