import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    build: {
        chunkSizeWarningLimit: 1000,
    },
    plugins: [
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
