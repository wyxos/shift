import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from "@tailwindcss/vite";
import fs from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';

const sharedUiPath = path.resolve(__dirname, '../shift-sdk-package/packages/shift-shared-ui/src');
const localSharedUiPath = path.resolve(__dirname, './resources/js/shared');
const sharedUiAlias = fs.existsSync(sharedUiPath) ? sharedUiPath : localSharedUiPath;

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
            '@shared': sharedUiAlias,
            '@tiptap': path.resolve(__dirname, './node_modules/@tiptap'),
            'highlight.js': path.resolve(__dirname, './node_modules/highlight.js'),
            lowlight: path.resolve(__dirname, './node_modules/lowlight'),
            'emoji-picker-element': path.resolve(__dirname, './node_modules/emoji-picker-element'),
            'lucide-vue-next': path.resolve(__dirname, './node_modules/lucide-vue-next'),
            axios: path.resolve(__dirname, './node_modules/axios'),
            'ziggy-js': resolve(__dirname, 'vendor/tightenco/ziggy'),
        },
    },
    server: {
        fs: {
            allow: [
                path.resolve(__dirname),
                sharedUiPath,
                localSharedUiPath,
            ],
        },
    },
});
