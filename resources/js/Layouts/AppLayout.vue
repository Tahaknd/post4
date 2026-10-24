<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import BaseLayout from '@/Layouts/BaseLayout.vue';
import { HOME_URL, HORIZON_URL, LOGOUT_URL } from '@/routes';

const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);

// The primary navigation exists for signed-in users only.
const navItems = [{ label: 'Home', href: HOME_URL }];

function logout() {
    router.post(LOGOUT_URL);
}
</script>

<template>
    <BaseLayout>
        <template v-if="user" #nav>
            <Link v-for="item in navItems" :key="item.href" :href="item.href" class="text-muted hover:text-fg">
                {{ item.label }}
            </Link>

            <!-- Horizon is a separate Laravel page, not an Inertia one: a plain link, shown to administrators only. -->
            <a v-if="user.is_admin" :href="HORIZON_URL" class="text-muted hover:text-fg">Horizon</a>

            <span class="hidden text-muted sm:inline" :title="user.email">{{ user.name }}</span>

            <button type="button" class="text-muted hover:text-fg" @click="logout">Sign out</button>
        </template>

        <slot />
    </BaseLayout>
</template>
