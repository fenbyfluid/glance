import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            buildDirectory: '_build',
            input: [
                'resources/css/app.css', 'resources/js/app.js',
                'resources/css/media.css', 'resources/js/media.js',
            ],
            refresh: true,
        }),
    ],
});
