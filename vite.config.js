import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    // Leave asset URLs in templates alone: Laravel serves /public, Vite does not.
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        // The browser, not the container, opens the HMR socket and public/hot is read by the app.
        // Without an explicit host a dev server bound to 0.0.0.0 (compose `vite` service) would
        // advertise http://[::]:5173.
        hmr: {
            host: process.env.VITE_HMR_HOST ?? 'localhost',
        },
    },
});
