import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    build: {
        chunkSizeWarningLimit: 1000,
    },
    plugins: [
        tailwindcss(),
        laravel({
            buildDirectory: '_build',
            input: [
                'resources/js/app.js',
                'resources/js/media.js',
            ],
            refresh: true,
        }),
    ],
});
