import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

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
  server: {
    // Hybrid dev mode: backend + db run in Docker (see docker-compose.dev.yml),
    // frontend runs standalone here via `npm run dev` (http://localhost:5173).
    // Mirrors the same-origin proxy the prod frontend Nginx container does
    // (frontend/docker/nginx.conf) so Sanctum's cookie auth keeps working
    // without needing CORS. Unused by the Laravel-served local dev mode.
    proxy: {
      '/api': {
        target: 'http://localhost:8090',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://localhost:8090',
        changeOrigin: true,
      },
    },
  },
});
