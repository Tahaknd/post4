<script setup>
import { Head } from '@inertiajs/vue3';

// The page shell of the Funnel insight; the builder and the result come with the next commits.
defineProps({
    project: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`Funnel - ${project.name}`" />

    <h1 class="text-2xl font-semibold tracking-tight">Funnel</h1>
</template>
