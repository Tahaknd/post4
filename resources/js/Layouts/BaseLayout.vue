<script setup>
import { Link, usePage } from '@inertiajs/vue3';

import { HOME_URL } from '@/routes';

const page = usePage();
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-canvas text-fg">
        <a
            href="#main"
            class="sr-only rounded-md bg-accent px-4 py-2 font-medium text-accent-fg focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50"
        >
            Skip to main content
        </a>

        <header class="border-b border-line bg-surface">
            <div class="relative mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3 sm:px-6">
                <Link :href="HOME_URL" class="text-lg font-semibold tracking-tight">
                    {{ page.props.app.name }}
                </Link>

                <nav v-if="$slots.nav" aria-label="Primary" class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                    <slot name="nav" />
                </nav>
            </div>
        </header>

        <!-- Always in the DOM: a live region only announces text that is inserted into one that already exists. -->
        <div class="mx-auto w-full max-w-6xl px-4 pt-4 sm:px-6" role="status" aria-live="polite" aria-atomic="true">
            <p v-if="page.props.flash?.status" class="rounded-md border border-line bg-surface px-3 py-2 text-sm">
                {{ page.props.flash.status }}
            </p>
        </div>

        <main id="main" tabindex="-1" class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 focus:outline-none sm:px-6">
            <slot />
        </main>

        <footer class="border-t border-line">
            <div class="mx-auto w-full max-w-6xl px-4 py-4 text-sm text-muted sm:px-6">
                Self-hosted product analytics
            </div>
        </footer>
    </div>
</template>
