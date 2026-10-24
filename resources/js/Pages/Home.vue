<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { PROJECTS_CREATE_URL, PROJECTS_URL, projectUrl } from '@/routes';

const page = usePage();

// The shared `projects` prop: id and name of the projects the user can open (at most 50), never a key.
const projects = computed(() => page.props.projects ?? []);
</script>

<template>
    <Head title="Home" />

    <section aria-labelledby="home-heading" class="space-y-6">
        <div class="space-y-2">
            <h1 id="home-heading" class="text-2xl font-semibold tracking-tight">
                Welcome, {{ page.props.auth.user.name }}
            </h1>
            <p class="max-w-prose text-muted">Event capture is running. Insights appear here once a project has events.</p>
        </div>

        <div v-if="projects.length === 0" class="max-w-prose rounded-md border border-line bg-surface px-4 py-6">
            <h2 class="font-medium">No projects yet</h2>
            <p class="mt-1 text-sm text-muted">A project gives you an API key to send events with.</p>
            <Link :href="PROJECTS_CREATE_URL" class="mt-3 inline-block font-medium text-accent hover:text-accent-hover">Create a project</Link>
        </div>

        <div v-else class="max-w-prose space-y-3">
            <h2 class="font-medium">Your projects</h2>
            <ul class="divide-y divide-line rounded-md border border-line bg-surface">
                <li v-for="project in projects" :key="project.id">
                    <Link :href="projectUrl(project.id)" class="block px-4 py-3 font-medium text-accent hover:text-accent-hover">{{ project.name }}</Link>
                </li>
            </ul>
            <p class="flex gap-4 text-sm">
                <Link :href="PROJECTS_URL" class="font-medium text-accent hover:text-accent-hover">All projects</Link>
                <Link :href="PROJECTS_CREATE_URL" class="font-medium text-accent hover:text-accent-hover">New project</Link>
            </p>
        </div>
    </section>
</template>
