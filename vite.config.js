import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => ({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/leaflet.entry.js',
                'resources/js/entries/demo-vendors.entry.js',
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        chunkSizeWarningLimit: mode === 'production' ? 1500 : 1000,
        rollupOptions: {
            output: {
                manualChunks: {
                    leaflet: ['leaflet'],
                },
            },
        },
    },
}));
