<script setup>
import { Link, usePage } from '@inertiajs/vue3';

import BaseLayout from '@/Layouts/BaseLayout.vue';
import { HOME_URL } from '@/routes';

const page = usePage();

// The primary navigation exists for signed-in users only; auth props are shared from 3.4 on.
const navItems = [{ label: 'Home', href: HOME_URL }];
</script>

<template>
    <BaseLayout>
        <template v-if="page.props.auth?.user" #nav>
            <Link v-for="item in navItems" :key="item.href" :href="item.href" class="text-muted hover:text-fg">
                {{ item.label }}
            </Link>
        </template>

        <slot />
    </BaseLayout>
</template>
