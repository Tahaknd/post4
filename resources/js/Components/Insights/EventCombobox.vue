<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';

import { formatNumber } from '@/lib/trendsFormat';

// One event name: the WAI-ARIA 1.2 editable combobox with list autocomplete (an input with role="combobox" that controls a
// listbox of suggestions, focus staying in the input, the highlighted option named by aria-activedescendant). The text IS the
// value: a name that is not in the list is fine (the list only shows the most frequent names of the last 30 days), so
// nothing has to be "added". Choosing an option puts its name into the input.
//
// The list opens on typing, on a click and on ArrowDown/ArrowUp, never just because the field got focus (a page of up to 8
// steps would announce and open a list at every tab stop). Keyboard: ArrowDown/ArrowUp open the list and move (wrapping); Enter picks the highlighted option (with none highlighted
// it only closes the list: the typed text already is the value); Escape closes the list; Tab leaves. Leaving the field
// trims the spaces around the name. The suggestions come from `names` (a useEventNames instance of the page, shared by
// all the steps so they share one cache and one request budget).
const props = defineProps({
    names: { type: Object, required: true },
    label: { type: String, required: true },
    limits: { type: Object, required: true },
    error: { type: String, default: undefined },
    // `data-field` for the page's "was this field left" tracking
    field: { type: String, default: undefined },
    labelClass: { type: String, default: 'sr-only' },
});

const model = defineModel({ type: String, required: true });

const id = useId();
const listboxId = `${id}-listbox`;
const errorId = `${id}-error`;
const optionId = (index) => `${id}-option-${index}`;

const root = ref(null);
const open = ref(false);
const active = ref(-1);
const announcement = ref('');
// An input method (IME) composition is in progress: the half-typed text is not a search and Enter belongs to the IME.
const composing = ref(false);
// The highlighted option changed through the keyboard (scroll to it); a mouse hover must not scroll the list.
let keyboardNav = false;

const options = computed(() => props.names.events.value);
const status = computed(() => props.names.status.value);

const message = computed(() => {
    if (status.value === 'error') {
        return props.names.errorMessage.value;
    }
    if (status.value === 'loading' && props.names.answeredFor.value === null) {
        return 'Loading events…';
    }
    if (status.value === 'ready' && options.value.length === 0) {
        return model.value.trim() === ''
            ? `No events in the last ${props.limits.event_window_days} days yet. Type an event name.`
            : 'No event matches. The name is used as typed.';
    }
    if (props.names.truncated.value) {
        return `Showing the ${options.value.length} most frequent events. Type to narrow the list.`;
    }

    return '';
});

const showPanel = computed(() => open.value && (options.value.length > 0 || message.value !== ''));
const expanded = computed(() => showPanel.value && options.value.length > 0);

function announce(value) {
    // Cleared first so the same sentence twice in a row is announced twice.
    announcement.value = '';
    nextTick(() => (announcement.value = value));
}

function search(immediate = false) {
    props.names.search(model.value, { immediate });
}

function openList() {
    if (!open.value) {
        open.value = true;
        search(true);
    }
}

function closeList() {
    open.value = false;
    active.value = -1;
}

function choose(option) {
    model.value = option.name;
    closeList();
    announce(`${option.name} chosen.`);
}

function onInput(event) {
    model.value = event.target.value;

    // While an input method composes, the text is not final: searching starts when the composition ends.
    if (event.isComposing || composing.value) {
        return;
    }

    open.value = true;
    active.value = -1;
    search();
}

function onCompositionEnd(event) {
    composing.value = false;
    model.value = event.target.value;
    open.value = true;
    active.value = -1;
    search();
}

function hover(index) {
    keyboardNav = false;
    active.value = index;
}

function move(delta) {
    const count = options.value.length;
    if (count === 0) {
        return;
    }

    keyboardNav = true;
    active.value = active.value === -1 ? (delta > 0 ? 0 : count - 1) : (active.value + delta + count) % count;
}

function onKeydown(event) {
    // Enter, arrows and Escape belong to the input method while it composes (keyCode 229 is how older browsers say so).
    if (event.isComposing || event.keyCode === 229) {
        return;
    }

    switch (event.key) {
        case 'ArrowDown':
        case 'ArrowUp':
            event.preventDefault();
            if (!open.value) {
                openList();
                active.value = -1;
            } else {
                move(event.key === 'ArrowDown' ? 1 : -1);
            }
            break;
        case 'Enter':
            // Never submits anything: the form runs on its button. With an option highlighted it is the pick.
            event.preventDefault();
            if (open.value && active.value >= 0 && options.value[active.value]) {
                choose(options.value[active.value]);
            } else {
                closeList();
            }
            break;
        case 'Escape':
            if (open.value) {
                event.preventDefault();
                event.stopPropagation();
                closeList();
            }
            break;
        default:
    }
}

// Focus leaving the whole widget closes the list and trims the name.
function onFocusOut(event) {
    if (!root.value?.contains(event.relatedTarget)) {
        closeList();
        const trimmed = model.value.trim();
        if (trimmed !== model.value) {
            model.value = trimmed;
        }
    }
}

// Keep the highlighted option visible.
watch(active, async (index) => {
    if (index < 0 || !keyboardNav) {
        return;
    }

    await nextTick();
    document.getElementById(optionId(index))?.scrollIntoView({ block: 'nearest' });
});

// The list got shorter (a new answer): the highlight must not point past its end.
watch(
    () => options.value.length,
    (count) => {
        if (active.value >= count) {
            active.value = count - 1;
        }
    },
);

// Announce what arrived while the list is open.
watch(status, (next) => {
    if (!open.value) {
        return;
    }
    if (next === 'ready') {
        const count = options.value.length;
        announce(count === 0 ? 'No matching events.' : `${count} ${count === 1 ? 'event' : 'events'} available.`);
    } else if (next === 'error') {
        announce(props.names.errorMessage.value);
    }
});
</script>

<template>
    <div ref="root" class="relative min-w-0" @focusout="onFocusOut">
        <label :for="id" :class="labelClass">{{ label }}</label>
        <input
            :id="id"
            :value="model"
            :data-field="field"
            type="text"
            role="combobox"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            placeholder="Search or type an event name"
            aria-autocomplete="list"
            :aria-expanded="expanded ? 'true' : 'false'"
            :aria-controls="listboxId"
            :aria-activedescendant="expanded && active >= 0 ? optionId(active) : undefined"
            :aria-describedby="error ? errorId : undefined"
            :aria-invalid="error ? 'true' : undefined"
            class="block w-full rounded-md border bg-surface px-3 py-2 text-fg placeholder:text-muted"
            :class="error ? 'border-danger' : 'border-line-strong'"
            @input="onInput"
            @compositionstart="composing = true"
            @compositionend="onCompositionEnd"
            @click="openList"
            @keydown="onKeydown"
        />

        <div v-show="showPanel" class="absolute inset-x-0 top-full z-30 mt-1 overflow-hidden rounded-md border border-line-strong bg-surface shadow-lg" @mousedown.prevent>
            <p v-if="message" class="border-b border-line px-3 py-2 text-sm text-muted">{{ message }}</p>
            <ul :id="listboxId" role="listbox" :aria-label="`${label}, matching events`" class="max-h-60 overflow-y-auto py-1" :aria-busy="status === 'loading' ? 'true' : undefined">
                <li
                    v-for="(option, index) in options"
                    :id="optionId(index)"
                    :key="option.name"
                    role="option"
                    :aria-selected="option.name === model ? 'true' : 'false'"
                    class="flex cursor-pointer items-center justify-between gap-3 px-3 py-2"
                    :class="index === active ? 'bg-accent text-accent-fg' : 'hover:bg-canvas'"
                    @click="choose(option)"
                    @mousemove="hover(index)"
                >
                    <span class="truncate" :title="option.name">{{ option.name }}</span>
                    <span class="shrink-0 text-sm tabular-nums" :class="index === active ? '' : 'text-muted'">{{ formatNumber(option.count) }}<span class="sr-only"> events</span></span>
                </li>
            </ul>
        </div>

        <p v-if="error" :id="errorId" role="alert" class="mt-1 text-sm text-danger">{{ error }}</p>

        <div class="sr-only" role="status" aria-live="polite" aria-atomic="true">{{ announcement }}</div>
    </div>
</template>
