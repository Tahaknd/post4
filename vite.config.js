import { fileURLToPath } from 'node:url';
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
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    build: {
        // The lazily loaded chart chunk (ECharts core + line/bar + grid, tooltip, legend, aria + the SVG renderer, about 550 kB
        // minified) is bigger than Vite's default 500 kB warning on purpose: it is not part of the entry bundle.
        chunkSizeWarningLimit: 700,
    },
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
