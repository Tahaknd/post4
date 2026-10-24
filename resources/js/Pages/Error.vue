<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import { HOME_URL } from '@/routes';

// Rendered by the server for HTML requests that fail on a web route (App\Http\ErrorPages): 403, 404,
// 429, 500 and 503. `message` is only ever a fixed, safe sentence chosen on the server.
const props = defineProps({
    status: { type: Number, required: true },
    message: { type: String, default: '' },
});

const titles = {
    403: 'Forbidden',
    404: 'Page not found',
    429: 'Too many attempts',
    500: 'Something went wrong',
    503: 'Temporarily unavailable',
};

const title = computed(() => titles[props.status] ?? 'Something went wrong');
</script>

<template>
    <Head :title="title" />

    <section aria-labelledby="error-heading" class="mx-auto w-full max-w-prose space-y-4 py-8">
        <p class="text-sm font-medium text-muted">Error {{ status }}</p>
        <h1 id="error-heading" class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
        <p v-if="message" class="text-muted">{{ message }}</p>
        <Link :href="HOME_URL" class="inline-block font-medium text-accent hover:text-accent-hover">Back to the start page</Link>
    </section>
</template>
