<script setup>
import { Head, Link } from '@inertiajs/vue3';

// Placeholder until the posthog-js test bench lands (next commit).
defineProps({
    project: { type: Object, required: true },
    api_key: { type: String, required: true },
    api_host: { type: String, required: true },
    urls: { type: Object, required: true },
});
</script>

<template>
    <Head title="Send test events" />

    <div class="max-w-3xl space-y-4">
        <p class="text-sm"><Link :href="urls.show" class="text-accent hover:text-accent-hover">{{ project.name }}</Link></p>
        <h1 class="text-2xl font-semibold tracking-tight">Send test events</h1>
    </div>
</template>
