import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from "@tailwindcss/vite";
import { resolve } from 'node:path';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
                compilerOptions: {
                    // Treat web components as custom elements
                    isCustomElement: (tag) => tag === 'emoji-picker',
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
            '@shared': path.resolve(__dirname, './resources/js/shared'),
            'ziggy-js': resolve(__dirname, 'vendor/tightenco/ziggy'),
        },
    },
    server: {
        fs: {
            allow: [
                path.resolve(__dirname),
                path.resolve(__dirname, './resources/js/shared'),
            ],
        },
    },
});
