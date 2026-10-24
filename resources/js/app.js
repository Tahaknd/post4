import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';

import AppLayout from '@/Layouts/AppLayout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

// One lazy chunk per page. Page components live in resources/js/Pages and are addressed by the
// name passed to Inertia::render(), e.g. 'Home' or 'Auth/Login'.
const pages = import.meta.glob('./Pages/**/*.vue');

// The app name comes from the server (shared prop `app.name`), read once in setup().
let appName = '';

createInertiaApp({
    title: (title) => [title, appName].filter(Boolean).join(' - '),
    resolve: async (name) => {
        const load = pages[`./Pages/${name}.vue`];

        if (!load) {
            throw new Error(`Unknown Inertia page: ${name}`);
        }

        const page = await load();

        // Layouts are assigned here, not per page. A page can still opt out or override with its own `layout`.
        page.default.layout ??= name.startsWith('Auth/') ? GuestLayout : AppLayout;

        return page;
    },
    setup({ el, App, props, plugin }) {
        appName = props.initialPage.props.app?.name ?? '';

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});

// After a client-side navigation move focus to <main> so keyboard and screen reader users start at
// the new content. Skipped for the first load, for partial reloads and for visits that stay on the same
// URL (form validation errors, filters), where moving focus would be disruptive.
let visit = null;

router.on('start', (event) => {
    const { only, except } = event.detail.visit;

    visit = {
        from: window.location.pathname + window.location.search,
        partial: only.length > 0 || except.length > 0,
    };
});

router.on('navigate', (event) => {
    const started = visit;
    visit = null;

    if (!started || started.partial || event.detail.page.url === started.from) {
        return;
    }

    requestAnimationFrame(() => document.getElementById('main')?.focus({ preventScroll: true }));
});
