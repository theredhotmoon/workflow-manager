import { fileURLToPath, URL } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [
    laravel({
      input: ['src/css/app.css', 'src/app.js'],
      publicDirectory: '../backend/public',
      buildDirectory: 'build',
      refresh: true,
    }),
    vue({
      template: {
        transformAssetUrls: {
          base: null,
          includeAbsolute: false,
        },
      },
    }),
    tailwindcss(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  // Hybrid dev mode (backend in Docker, SPA on :5173) uses vite.config.hybrid.js.
  test: {
    environment: 'happy-dom',
    setupFiles: ['./src/mocks/setup.js'],
    globals: false,
  },
});
