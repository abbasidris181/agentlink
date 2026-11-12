import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Inter', {
                    weights: [300, 400, 500, 600, 700],
                    preload: false,
                    optimizedFallbacks: false,
                }),
                bunny('Roboto', {
                    weights: [300, 400, 500, 600, 700],
                    preload: [{ weight: 400 }, { weight: 600 }, { weight: 700 }],
                    optimizedFallbacks: false,
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
