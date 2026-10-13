import inertia from '@inertiajs/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

const vitePort = Number(process.env.VITE_PORT ?? 5173);

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        inertia(),
        vue({
            template: {
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
    ],
    server: {
        // Runs inside the "node" container; the browser reaches it via the loopback port mapping.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: `http://localhost:${vitePort}`,
        cors: { origin: /^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/ },
        hmr: { host: 'localhost', clientPort: vitePort },
        watch: {
            usePolling: process.env.VITE_USE_POLLING === 'true',
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
});
