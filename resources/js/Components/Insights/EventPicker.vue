<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';

import { useEventNames } from '@/composables/useEventNames';
import { codePoints, formatNumber } from '@/lib/trendsFormat';

// Multi-select event picker: the WAI-ARIA 1.2 combobox pattern (an input with role="combobox" that controls a listbox
// of options, focus staying in the input, the highlighted option named by aria-activedescendant), with the picked
// events as removable chips in front of the input.
//
// Keyboard: ArrowDown/ArrowUp open the list and move (wrapping); Home/End jump to the first/last option while the list
// is open and either nothing is typed or an option is highlighted (otherwise they move the text caret as usual); Enter
// picks or unpicks the highlighted option, or, with no option highlighted, ADDS THE TYPED TEXT as an event name (a name
// that is not in the list is fine: the list only shows the most frequent names of the last 30 days); Escape closes the
// list (and, when it is closed, clears the text); Backspace in an empty input removes the last chip.
// A polite live region announces what happened (results, picks, removals, errors); the visible text mirrors it.
const props = defineProps({
    eventsUrl: { type: String, required: true },
    limits: { type: Object, required: true },
    error: { type: String, default: undefined },
});

const selected = defineModel({ type: Array, required: true });

const id = useId();
const inputId = `${id}-input`;
const listboxId = `${id}-listbox`;
const hintId = `${id}-hint`;
const errorId = `${id}-error`;
const optionId = (index) => `${id}-option-${index}`;

const root = ref(null);
const input = ref(null);
const listbox = ref(null);
const text = ref('');
const open = ref(false);
const active = ref(-1);
const announcement = ref('');
// An input method (IME) composition is in progress: the half-typed text is not a search and Enter belongs to the IME.
const composing = ref(false);
// The highlighted option changed through the keyboard (scroll to it); a mouse hover must not scroll the list.
let keyboardNav = false;
// After a pick the list reloads; its "N events available" must not replace the "added" announcement.
let quietReload = false;

const names = useEventNames(props.eventsUrl, props.limits.max_search_length);

const trimmed = computed(() => text.value.trim());
const atLimit = computed(() => selected.value.length >= props.limits.max_events);

/** The options: the server's names, then (last) the typed text when it is not one of them. */
const options = computed(() => {
    const list = names.events.value.map((event) => ({ name: event.name, count: event.count, custom: false }));
    const typed = trimmed.value;

    if (typed !== '' && codePoints(typed) <= props.limits.max_event_length && !list.some((option) => option.name === typed)) {
        list.push({ name: typed, count: null, custom: true });
    }

    return list;
});

const message = computed(() => {
    if (names.status.value === 'error') {
        return names.errorMessage.value;
    }
    if (names.status.value === 'loading' && names.answeredFor.value === null) {
        return 'Loading events…';
    }
    if (names.status.value === 'ready' && names.events.value.length === 0) {
        return trimmed.value === ''
            ? `No events in the last ${props.limits.event_window_days} days yet. Type an event name and press Enter.`
            : `No events match “${trimmed.value}”. Press Enter to use it as typed.`;
    }
    if (names.truncated.value) {
        return `Showing the ${names.events.value.length} most frequent events. Type to narrow the list.`;
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

function openList() {
    if (!open.value) {
        open.value = true;
        names.search(text.value, { immediate: true });
    }
}

function closeList() {
    open.value = false;
    active.value = -1;
}

function isSelected(name) {
    return selected.value.includes(name);
}

function add(name) {
    if (isSelected(name)) {
        announce(`${name} is already selected.`);

        return false;
    }
    if (atLimit.value) {
        announce(`You can pick at most ${props.limits.max_events} events.`);

        return false;
    }
    if (codePoints(name) < 1 || codePoints(name) > props.limits.max_event_length) {
        announce(`Event names are 1 to ${props.limits.max_event_length} characters.`);

        return false;
    }

    selected.value = [...selected.value, name];
    announce(`${name} added. ${selected.value.length} of ${props.limits.max_events} events selected.`);

    return true;
}

function remove(name) {
    selected.value = selected.value.filter((item) => item !== name);
    announce(`${name} removed. ${selected.value.length} of ${props.limits.max_events} events selected.`);
}

function toggle(option) {
    if (isSelected(option.name)) {
        remove(option.name);

        return;
    }

    if (add(option.name)) {
        // Ready for the next one: the typed text is consumed, the list stays open with the full list.
        text.value = '';
        active.value = -1;
        reload();
    }
}

function reload() {
    quietReload = true;
    names.search('', { immediate: true });
}

function removeChip(name) {
    remove(name);
    nextTick(() => input.value?.focus());
}

function onInput(event) {
    quietReload = false;
    text.value = event.target.value;

    // While an input method composes, the text is not final: searching starts when the composition ends.
    if (event.isComposing || composing.value) {
        return;
    }

    open.value = true;
    active.value = -1;
    names.search(text.value);
}

function hover(index) {
    keyboardNav = false;
    active.value = index;
}

function onCompositionEnd(event) {
    composing.value = false;
    text.value = event.target.value;
    open.value = true;
    active.value = -1;
    names.search(text.value);
}

function move(delta, count) {
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

    const count = options.value.length;

    switch (event.key) {
        case 'ArrowDown':
        case 'ArrowUp':
            event.preventDefault();
            if (!open.value) {
                openList();
                active.value = -1;
            } else {
                move(event.key === 'ArrowDown' ? 1 : -1, count);
            }
            break;
        case 'Home':
        case 'End':
            if (open.value && count > 0 && (text.value === '' || active.value >= 0)) {
                event.preventDefault();
                keyboardNav = true;
                active.value = event.key === 'Home' ? 0 : count - 1;
            }
            break;
        case 'Enter':
            if (open.value && active.value >= 0 && options.value[active.value]) {
                event.preventDefault();
                toggle(options.value[active.value]);
            } else if (trimmed.value !== '') {
                event.preventDefault();
                if (add(trimmed.value)) {
                    text.value = '';
                    reload();
                }
            }
            break;
        case 'Escape':
            if (open.value) {
                event.preventDefault();
                event.stopPropagation();
                closeList();
            } else if (text.value !== '') {
                event.preventDefault();
                text.value = '';
            }
            break;
        case 'Backspace':
            // `repeat`: holding the key down must not eat every chip.
            if (text.value === '' && selected.value.length > 0 && !event.repeat) {
                remove(selected.value[selected.value.length - 1]);
            }
            break;
        default:
    }
}

// Focus leaving the whole widget closes the list.
function onFocusOut(event) {
    if (!root.value?.contains(event.relatedTarget)) {
        closeList();
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
watch(
    () => names.status.value,
    (status) => {
        if (!open.value) {
            return;
        }
        if (status === 'ready') {
            if (quietReload) {
                quietReload = false;

                return;
            }
            const count = options.value.filter((option) => !option.custom).length;
            announce(count === 0 ? 'No matching events.' : `${count} ${count === 1 ? 'event' : 'events'} available.${names.truncated.value ? ' More exist: type to narrow the list.' : ''}`);
        } else if (status === 'error') {
            announce(names.errorMessage.value);
        }
    },
);

const describedBy = computed(() => [hintId, props.error && errorId].filter(Boolean).join(' '));
</script>

<template>
    <div ref="root" class="space-y-1.5" @focusout="onFocusOut">
        <label :for="inputId" class="block text-sm font-medium">
            Events
            <span class="font-normal text-muted">({{ selected.length }} of {{ limits.max_events }})</span>
        </label>

        <div class="relative">
            <div
                class="flex min-h-[2.75rem] flex-wrap items-center gap-1.5 rounded-md border bg-surface px-2 py-1.5 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-focus"
                :class="error ? 'border-danger' : 'border-line-strong'"
                @click="input?.focus()"
            >
                <ul v-if="selected.length > 0" aria-label="Selected events" class="contents">
                    <li v-for="name in selected" :key="name" class="inline-flex max-w-full items-center gap-1 rounded-md border border-line-strong bg-canvas py-0.5 pl-2 pr-0.5 text-sm">
                        <span class="max-w-[14rem] truncate sm:max-w-[20rem]" :title="name">{{ name }}</span>
                        <button
                            type="button"
                            class="inline-flex h-6 w-6 items-center justify-center rounded text-muted hover:bg-line hover:text-fg"
                            :aria-label="`Remove ${name}`"
                            @click.stop="removeChip(name)"
                        >
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </li>
                </ul>

                <input
                    :id="inputId"
                    ref="input"
                    :value="text"
                    type="text"
                    role="combobox"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    :placeholder="selected.length === 0 ? 'Search or type an event name' : ''"
                    aria-autocomplete="list"
                    :aria-expanded="expanded ? 'true' : 'false'"
                    :aria-controls="listboxId"
                    :aria-activedescendant="expanded && active >= 0 ? optionId(active) : undefined"
                    :aria-describedby="describedBy"
                    :aria-invalid="error ? 'true' : undefined"
                    :maxlength="limits.max_event_length"
                    class="min-w-[10rem] flex-1 bg-transparent px-1 py-1 text-fg placeholder:text-muted focus-visible:outline-none"
                    @input="onInput"
                    @compositionstart="composing = true"
                    @compositionend="onCompositionEnd"
                    @focus="openList"
                    @click="openList"
                    @keydown="onKeydown"
                />
            </div>

            <div
                v-show="showPanel"
                class="absolute inset-x-0 top-full z-30 mt-1 overflow-hidden rounded-md border border-line-strong bg-surface shadow-lg"
                @mousedown.prevent
            >
                <p v-if="message" class="border-b border-line px-3 py-2 text-sm text-muted">{{ message }}</p>
                <ul
                    :id="listboxId"
                    ref="listbox"
                    role="listbox"
                    aria-label="Matching events"
                    aria-multiselectable="true"
                    class="max-h-72 overflow-y-auto py-1"
                    :aria-busy="names.status.value === 'loading' ? 'true' : undefined"
                >
                    <li
                        v-for="(option, index) in options"
                        :id="optionId(index)"
                        :key="`${option.custom ? 'c' : 'e'}:${option.name}`"
                        role="option"
                        :aria-selected="isSelected(option.name) ? 'true' : 'false'"
                        class="flex cursor-pointer items-center justify-between gap-3 px-3 py-2"
                        :class="index === active ? 'bg-accent text-accent-fg' : 'hover:bg-canvas'"
                        @click="toggle(option)"
                        @mousemove="hover(index)"
                    >
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="w-4 shrink-0" aria-hidden="true">{{ isSelected(option.name) ? '✓' : '' }}</span>
                            <span class="truncate" :title="option.name">
                                <template v-if="option.custom">Use &ldquo;{{ option.name }}&rdquo;</template>
                                <template v-else>{{ option.name }}</template>
                            </span>
                        </span>
                        <span v-if="!option.custom" class="shrink-0 text-sm tabular-nums" :class="index === active ? '' : 'text-muted'">
                            {{ formatNumber(option.count) }}<span class="sr-only"> events</span>
                        </span>
                        <span v-else class="shrink-0 text-sm" :class="index === active ? '' : 'text-muted'">as typed</span>
                    </li>
                </ul>
            </div>
        </div>

        <p :id="hintId" class="text-sm text-muted">
            Pick up to {{ limits.max_events }} events. The list shows the most frequent names of the last {{ limits.event_window_days }} days; type any
            other name and press Enter.
        </p>
        <p v-if="error" :id="errorId" role="alert" class="text-sm text-danger">{{ error }}</p>

        <div class="sr-only" role="status" aria-live="polite" aria-atomic="true">{{ announcement }}</div>
    </div>
</template>
