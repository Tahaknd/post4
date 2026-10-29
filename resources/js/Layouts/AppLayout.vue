<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import ProjectMenu from '@/Components/ProjectMenu.vue';
import BaseLayout from '@/Layouts/BaseLayout.vue';
import { HOME_URL, HORIZON_URL, LOGOUT_URL } from '@/routes';

const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);

// The insight pages of the project on screen (server-built URLs, null on pages without a project).
const trendsUrl = computed(() => page.props.trendsUrl ?? null);
const onTrends = computed(() => trendsUrl.value !== null && page.url.split('?')[0] === trendsUrl.value);
const funnelUrl = computed(() => page.props.funnelUrl ?? null);
const onFunnel = computed(() => funnelUrl.value !== null && page.url.split('?')[0] === funnelUrl.value);

// The primary navigation exists for signed-in users only.
const navItems = [{ label: 'Home', href: HOME_URL }];

const signingOut = ref(false);

// aria-disabled instead of disabled (see PrimaryButton): the button keeps focus while the request runs.
function logout() {
    if (signingOut.value) {
        return;
    }

    signingOut.value = true;
    router.post(LOGOUT_URL, {}, { onFinish: () => (signingOut.value = false) });
}
</script>

<template>
    <BaseLayout>
        <template v-if="user" #nav>
            <Link v-for="item in navItems" :key="item.href" :href="item.href" class="text-muted hover:text-fg">
                {{ item.label }}
            </Link>

            <ProjectMenu />

            <Link v-if="trendsUrl" :href="trendsUrl" class="text-muted hover:text-fg" :class="onTrends ? 'font-medium text-fg' : ''" :aria-current="onTrends ? 'page' : undefined">Trends</Link>
            <Link v-if="funnelUrl" :href="funnelUrl" class="text-muted hover:text-fg" :class="onFunnel ? 'font-medium text-fg' : ''" :aria-current="onFunnel ? 'page' : undefined">Funnel</Link>

            <!-- Horizon is a separate Laravel page, not an Inertia one: a plain link, shown to administrators only. -->
            <a v-if="user.is_admin" :href="HORIZON_URL" class="text-muted hover:text-fg">Horizon</a>

            <span class="max-w-32 truncate text-muted" :title="user.email">{{ user.name }}</span>

            <button
                type="button"
                class="text-muted hover:text-fg aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
                :aria-disabled="signingOut ? 'true' : undefined"
                @click="logout"
            >
                Sign out
            </button>
        </template>

        <slot />
    </BaseLayout>
</template>
