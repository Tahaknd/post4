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
            <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <Link :href="HOME_URL" class="text-lg font-semibold tracking-tight">
                    {{ page.props.app.name }}
                </Link>

                <nav v-if="$slots.nav" aria-label="Primary" class="flex items-center gap-4 text-sm">
                    <slot name="nav" />
                </nav>
            </div>
        </header>

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
