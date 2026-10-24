<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId } from 'vue';

import { PROJECTS_CREATE_URL, PROJECTS_URL } from '@/routes';

// The project switcher of the navigation, as a disclosure (a button that shows a list of links). Choosing a
// project follows a link instead of changing a <select>, so nothing navigates by itself while someone arrows
// through the options. `projects` is the shared lazy prop: id, name and the server-built url, at most 50, never a
// key; the server always includes the project on screen, and `projectsTruncated` says there are more than listed.
// The current project is the one the page itself carries (`project` prop of the project page).
// Layout: below the sm breakpoint the panel is anchored to the header (BaseLayout makes it `relative`) and spans its
// width, because the button can sit anywhere in a wrapped navigation; from sm up it hangs under the button.
const page = usePage();

const open = ref(false);
const button = ref(null);
const panel = ref(null);
const panelId = useId();

const projects = computed(() => page.props.projects ?? []);
const truncated = computed(() => page.props.projectsTruncated === true);
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
    <div class="sm:relative" @focusout="onFocusOut" @keydown.esc="open && close(true)">
        <button
            ref="button"
            type="button"
            class="inline-flex max-w-48 items-center gap-1 text-muted hover:text-fg"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="panelId"
            @click="toggle"
        >
            <span class="truncate"><span class="sr-only">Project: </span>{{ current ? current.name : 'Projects' }}</span>
            <span aria-hidden="true">&#9662;</span>
        </button>

        <div
            v-show="open"
            :id="panelId"
            ref="panel"
            class="absolute inset-x-4 top-full z-40 rounded-md border border-line-strong bg-surface py-1 shadow-lg sm:inset-x-auto sm:left-0 sm:mt-2 sm:w-64 sm:max-w-[calc(100vw-2rem)]"
        >
            <ul class="max-h-72 overflow-y-auto">
                <li v-for="project in projects" :key="project.id">
                    <Link
                        :href="project.url"
                        class="block truncate px-3 py-2 hover:bg-canvas"
                        :class="project.id === currentId ? 'font-medium text-fg' : 'text-muted'"
                        :aria-current="project.id === currentId ? 'page' : undefined"
                        @click="close()"
                    >
                        {{ project.name }}
                    </Link>
                </li>
            </ul>
            <p v-if="truncated" class="px-3 py-2 text-xs text-muted">Showing the first {{ projects.length }} projects.</p>
            <div class="border-t border-line pt-1">
                <Link :href="PROJECTS_URL" class="block px-3 py-2 text-muted hover:bg-canvas" @click="close()">All projects</Link>
                <Link :href="PROJECTS_CREATE_URL" class="block px-3 py-2 text-muted hover:bg-canvas" @click="close()">New project</Link>
            </div>
        </div>
    </div>
</template>
