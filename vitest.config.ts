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
    },
  },
})
