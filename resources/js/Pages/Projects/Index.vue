<script setup>
import { Head, Link } from '@inertiajs/vue3';

// Every project the user can open, with the link to each (built on the server). No keys: they exist only on a
// project's own page.
defineProps({
    projects: { type: Array, default: () => [] },
    create_url: { type: String, required: true },
});

const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });

function created(iso) {
    return iso ? dateFormat.format(new Date(iso)) : '';
}
</script>

<template>
    <Head title="Projects" />

    <section aria-labelledby="projects-heading" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 id="projects-heading" class="text-2xl font-semibold tracking-tight">Projects</h1>
            <Link :href="create_url" class="inline-flex items-center rounded-md bg-accent px-4 py-2 font-medium text-accent-fg hover:bg-accent-hover">
                New project
            </Link>
        </div>

        <div v-if="projects.length === 0" class="max-w-prose rounded-md border border-line bg-surface px-4 py-6">
            <h2 class="font-medium">No projects yet</h2>
            <p class="mt-1 text-sm text-muted">Create a project to get an API key and start sending events.</p>
            <Link :href="create_url" class="mt-3 inline-block font-medium text-accent hover:text-accent-hover">Create a project</Link>
        </div>

        <div v-else class="overflow-x-auto rounded-md border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Projects you can open</caption>
                <thead class="border-b border-line text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2 font-medium">Name</th>
                        <th scope="col" class="px-4 py-2 font-medium">Timezone</th>
                        <th scope="col" class="px-4 py-2 font-medium">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="project in projects" :key="project.id">
                        <th scope="row" class="px-4 py-3 font-medium">
                            <Link :href="project.url" class="text-accent hover:text-accent-hover">{{ project.name }}</Link>
                        </th>
                        <td class="px-4 py-3">{{ project.timezone }}</td>
                        <td class="px-4 py-3">{{ created(project.created_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
