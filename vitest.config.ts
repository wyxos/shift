import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [
    vue({
      template: {
        compilerOptions: {
          isCustomElement: (tag) => tag === 'emoji-picker',
        },
      },
    }),
  ],
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./resources/js/__tests__/setupTests.ts'],
    include: [
      'resources/js/**/__tests__/**/*.{test,spec}.{ts,tsx,js,jsx}',
      'resources/js/**/*.{test,spec}.{ts,tsx,js,jsx}',
    ],
    exclude: [
      'node_modules/**',
      'vendor/**',
    ],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html'],
    },
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
      '@shared': fileURLToPath(new URL('./resources/js/shared', import.meta.url)),
      '@tiptap': fileURLToPath(new URL('./node_modules/@tiptap', import.meta.url)),
      'highlight.js': fileURLToPath(new URL('./node_modules/highlight.js', import.meta.url)),
      lowlight: fileURLToPath(new URL('./node_modules/lowlight', import.meta.url)),
      'emoji-picker-element': fileURLToPath(new URL('./node_modules/emoji-picker-element', import.meta.url)),
      'lucide-vue-next': fileURLToPath(new URL('./node_modules/lucide-vue-next', import.meta.url)),
      axios: fileURLToPath(new URL('./node_modules/axios', import.meta.url)),
    },
  },
})
