<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    project: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`Trends - ${project.name}`" />
    <h1 class="text-2xl font-semibold tracking-tight">Trends</h1>
</template>
