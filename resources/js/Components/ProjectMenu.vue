<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId } from 'vue';

import { PROJECTS_CREATE_URL, PROJECTS_URL, projectUrl } from '@/routes';

// The project switcher of the navigation, as a disclosure (a button that shows a list of links). Choosing a
// project follows a link instead of changing a <select>, so nothing navigates by itself while someone arrows
// through the options. `projects` is the shared lazy prop: id and name only, at most 50, never a key. The current
// project is the one the page itself carries (`project` prop of the project page).
const page = usePage();

const open = ref(false);
const button = ref(null);
const panel = ref(null);
const panelId = useId();

const projects = computed(() => page.props.projects ?? []);
const currentId = computed(() => page.props.project?.id ?? null);
const current = computed(() => projects.value.find((project) => project.id === currentId.value) ?? null);

function close(returnFocus = false) {
    open.value = false;

    if (returnFocus) {
        nextTick(() => button.value?.focus());
    }
}

async function toggle() {
    open.value = !open.value;

    if (open.value) {
        await nextTick();
        // Land on the current project, or the first link.
        (panel.value?.querySelector('[aria-current="page"]') ?? panel.value?.querySelector('a'))?.focus();
    }
}

// Focus leaving the whole widget closes it; Escape closes it and puts focus back on the button.
function onFocusOut(event) {
    if (!event.currentTarget.contains(event.relatedTarget)) {
        close();
    }
}
</script>

<template>
    <div class="relative" @focusout="onFocusOut" @keydown.esc="open && close(true)">
        <button
            ref="button"
            type="button"
            class="inline-flex max-w-48 items-center gap-1 text-muted hover:text-fg"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="panelId"
            @click="toggle"
        >
            <span class="truncate">{{ current ? current.name : 'Projects' }}</span>
            <span aria-hidden="true">&#9662;</span>
        </button>

        <div
            v-show="open"
            :id="panelId"
            ref="panel"
            class="absolute left-0 top-full z-40 mt-2 w-64 rounded-md border border-line-strong bg-surface py-1 shadow-lg"
        >
            <ul class="max-h-72 overflow-y-auto">
                <li v-for="project in projects" :key="project.id">
                    <Link
                        :href="projectUrl(project.id)"
                        class="block truncate px-3 py-2 hover:bg-canvas"
                        :class="project.id === currentId ? 'font-medium text-fg' : 'text-muted'"
                        :aria-current="project.id === currentId ? 'page' : undefined"
                        @click="close()"
                    >
                        {{ project.name }}
                    </Link>
                </li>
            </ul>
            <div class="border-t border-line pt-1">
                <Link :href="PROJECTS_URL" class="block px-3 py-2 text-muted hover:bg-canvas" @click="close()">All projects</Link>
                <Link :href="PROJECTS_CREATE_URL" class="block px-3 py-2 text-muted hover:bg-canvas" @click="close()">New project</Link>
            </div>
        </div>
    </div>
</template>
