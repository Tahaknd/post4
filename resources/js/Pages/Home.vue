<script setup>
import { Head, usePage } from '@inertiajs/vue3';

// Projects the signed-in user can open, as { id, name }. Linking to a project comes with the project pages.
defineProps({
    projects: { type: Array, default: () => [] },
});

const page = usePage();
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
            <p class="mt-1 text-sm text-muted">Projects you can open will be listed here.</p>
        </div>

        <ul v-else class="max-w-prose divide-y divide-line rounded-md border border-line bg-surface">
            <li v-for="project in projects" :key="project.id" class="px-4 py-3">{{ project.name }}</li>
        </ul>
    </section>
</template>
